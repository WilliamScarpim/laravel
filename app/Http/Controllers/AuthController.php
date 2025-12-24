<?php

namespace App\Http\Controllers;

use App\Models\TwoFactorChallenge;
use App\Models\User;
use App\Notifications\TwoFactorCodeNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'context' => ['nullable', 'string', 'in:doctor,company,admin'],
        ]);

        /** @var User|null $user */
        $user = User::where('email', $credentials['email'])->first();
        $invalidCredentialsMessage = 'E-mail ou senha inválidos.';
        $error = ValidationException::withMessages([
            'email' => [$invalidCredentialsMessage],
        ]);
        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            Log::warning('Failed login attempt', [
                'email' => $credentials['email'],
                'ip' => $request->ip(),
            ]);

            throw $error;
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Conta inativa. Confira o e-mail de ativaÃ§Ã£o ou contate o suporte.'],
            ]);
        }

        if (! empty($credentials['context']) && $user->role !== $credentials['context']) {
            throw $error;
        }

        $challenge = $this->createTwoFactorChallenge($user, $request);

        Log::info('login.challenge.created', [
            'user_id' => $user->id,
            'email' => $user->email,
            'challenge_id' => $challenge->id,
            'context' => $credentials['context'] ?? null,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'challengeId' => $challenge->id,
            'expiresAt' => optional($challenge->expires_at)?->toIso8601String(),
            'user' => [
                'email' => $user->email,
                'role' => $user->role,
                'name' => $user->name,
            ],
        ], 201);
    }

    public function verifyTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challengeId' => ['required', 'string'],
            'code' => ['required', 'numeric', 'digits:6'],
        ]);

        /** @var TwoFactorChallenge|null $challenge */
        $challenge = TwoFactorChallenge::query()
            ->with('user.company')
            ->where('id', $data['challengeId'])
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $challenge || ! Hash::check($data['code'], $challenge->code_hash)) {
            throw ValidationException::withMessages([
                'code' => ['Código inválido ou expirado.'],
            ]);
        }

        $user = $challenge->user;
        if (! $user || ! $user->is_active) {
            throw ValidationException::withMessages([
                'code' => ['A conta está indisponível.'],
            ]);
        }

        $challenge->forceFill([
            'consumed_at' => now(),
            'ip_address' => $request->ip(),
        ])->save();

        Auth::login($user);
        $request->session()->regenerate();

        Log::info('login.two_factor.success', [
            'user_id' => $user->id,
            'challenge_id' => $challenge->id,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'user' => $this->transformUser($user),
        ]);
    }

    public function resendTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challengeId' => ['required', 'string'],
        ]);

        /** @var TwoFactorChallenge|null $challenge */
        $challenge = TwoFactorChallenge::with('user')
            ->where('id', $data['challengeId'])
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $challenge) {
            throw ValidationException::withMessages([
                'challengeId' => ['Esse código expirou. Faça login novamente.'],
            ]);
        }

        if ($challenge->created_at && now()->diffInSeconds($challenge->created_at) < 60) {
            throw ValidationException::withMessages([
                'challengeId' => ['Aguarde alguns segundos antes de reenviar o código.'],
            ]);
        }

        $newChallenge = $this->createTwoFactorChallenge($challenge->user, $request);

        return response()->json([
            'challengeId' => $newChallenge->id,
            'expiresAt' => optional($newChallenge->expires_at)?->toIso8601String(),
            'user' => [
                'email' => $challenge->user->email,
                'role' => $challenge->user->role,
                'name' => $challenge->user->name,
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($request->isMethod('patch')) {
            $rules = [
                'name' => ['sometimes', 'string', 'max:255'],
                'email' => ['sometimes', 'email', 'max:255', 'unique:users,email,' . $user->id],
            ];

            if ($user->isDoctor()) {
                $rules['crm'] = ['sometimes', 'string', 'max:20'];
                $rules['rqe'] = ['sometimes', 'nullable', 'string', 'max:30'];
            }

            $data = $request->validate($rules);
            $before = $user->only(array_keys($data));

            if (array_key_exists('name', $data)) {
                $user->name = $data['name'];
            }

            if (array_key_exists('email', $data)) {
                $user->email = $data['email'];
            }

            if ($user->isDoctor()) {
                if (array_key_exists('crm', $data)) {
                    $user->crm = Str::upper(trim((string) $data['crm']));
                }
                if (array_key_exists('rqe', $data)) {
                    $user->rqe = $data['rqe'] ?? null;
                }
            }

            $dirty = $user->getDirty();
            if (! empty($dirty)) {
                $user->save();
                app(\App\Services\UserAuditService::class)->record($user, $before, $user);
            }
        }

        return response()->json([
            'user' => $this->transformUser($user->fresh()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Sessão encerrada']);
    }

    private function transformUser(User $user): array
    {
        $user->loadMissing(['company', 'specialtyRelation']);
        $company = $user->isCompany()
            ? $user
            : $user->company;

        return [
            'id' => (string) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'isActive' => (bool) $user->is_active,
            'specialtyId' => $user->specialtyRelation?->id,
            'doctor' => $user->isDoctor() ? [
                'crm' => $user->crm,
                'specialty' => $user->specialtyRelation?->name ?? $user->specialty,
                'specialtyId' => $user->specialtyRelation?->id,
                'rqe' => $user->rqe,
            ] : null,
            'company' => $company ? [
                'id' => (string) $company->id,
                'name' => $company->name,
            ] : null,
        ];
    }

    private function createTwoFactorChallenge(User $user, Request $request): TwoFactorChallenge
    {
        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);

        TwoFactorChallenge::query()
            ->where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->delete();

        $challenge = TwoFactorChallenge::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'type' => 'login',
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(10),
            'ip_address' => $request->ip(),
        ]);

        Log::info('login.challenge.new', [
            'user_id' => $user->id,
            'challenge_id' => $challenge->id,
            'ip' => $request->ip(),
        ]);

        $user->notify(new TwoFactorCodeNotification($code));

        return $challenge;
    }
}
