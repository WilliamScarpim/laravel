<?php

namespace App\Http\Controllers;

use App\Models\Specialty;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class RegistrationController extends Controller
{
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
            'is_active' => true,
        ];

        if ($data['role'] === 'doctor') {
            $crm = Str::upper(trim((string) $data['crm']));
            $attributes['crm'] = $crm;
            $attributes['specialty'] = $specialty?->name;
            $attributes['specialty_id'] = $specialty?->id;
            $attributes['rqe'] = $specialty ? ($data['rqe'] ?? null) : null;
        }

        $user = User::create($attributes);

        return response()->json([
            'message' => 'Conta criada com sucesso. Verifique seu e-mail para ativar a autenticação.',
            'user' => [
                'id' => (string) $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ], 201);
    }
}
