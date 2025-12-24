<?php

namespace App\Http\Controllers;

use App\Models\Specialty;
use App\Models\User;
use App\Models\UserActivationToken;
use App\Services\AccountActivationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class RegistrationController extends Controller
{
    public function __construct(private readonly AccountActivationService $activationService)
    {
    }

    public function store(Request $request): JsonResponse
    {
        if ($request->filled('crm')) {
            $request->merge([
                'crm' => Str::upper(trim((string) $request->input('crm'))),
            ]);
        }

        $crmPattern = '/^[0-9]{4,10}(?:-[0-9])?\/[A-Za-z]{2}$/';

        $data = $request->validate([
            'role' => ['required', 'string', 'in:company,doctor'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'crm' => ['required_if:role,doctor', 'nullable', 'string', 'max:20', "regex:{$crmPattern}"],
            'specialtyId' => ['nullable', 'integer', 'exists:specialties,id'],
            'rqe' => ['nullable', 'string', 'max:30'],
        ]);

        $specialtyId = $data['specialtyId'] ?? null;
        $specialty = null;
        if ($data['role'] === 'doctor' && $specialtyId) {
            $specialty = Specialty::find($specialtyId);
        }

        $attributes = [
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => $data['role'],
            'is_active' => false,
        ];

        if ($data['role'] === 'doctor') {
            $crm = Str::upper(trim((string) $data['crm']));
            $attributes['crm'] = $crm;
            $attributes['specialty'] = $specialty?->name;
            $attributes['specialty_id'] = $specialty?->id;
            $attributes['rqe'] = $specialty ? ($data['rqe'] ?? null) : null;
        }

        $user = User::create($attributes);
        $this->activationService->send($user);

        return response()->json([
            'message' => 'Conta criada. Enviamos um e-mail para ativação.',
            'user' => [
                'id' => (string) $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ], 201);
    }

    public function activate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
        ]);

        /** @var UserActivationToken|null $record */
        $record = UserActivationToken::with('user')
            ->where('token', $data['token'])
            ->first();

        if (! $record || ! $record->isValid()) {
            return response()->json([
                'message' => 'Token inválido ou expirado.',
            ], 422);
        }

        $user = $record->user;
        if (! $user) {
            return response()->json([
                'message' => 'Usuário não encontrado para ativação.',
            ], 404);
        }

        $user->is_active = true;
        $user->email_verified_at = now();
        $user->save();

        $record->forceFill(['used_at' => now()])->save();

        return response()->json([
            'message' => 'Conta ativada com sucesso.',
        ]);
    }
}
