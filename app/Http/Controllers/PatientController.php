<?php

namespace App\Http\Controllers;

use App\Models\Patient;
use Illuminate\Http\Request;

class PatientController extends Controller
{
    public function search(Request $request)
    {
        $cpf = preg_replace('/\D/', '', (string) $request->query('cpf'));
        if (! $cpf) {
            return response()->json(['message' => 'CPF é obrigatório'], 422);
        }

        $patient = Patient::whereRaw('REPLACE(REPLACE(REPLACE(cpf, ".", ""), "-", ""), "/", "") = ?', [$cpf])->first();

        if (! $patient) {
            return response()->json(['patient' => null], 404);
        }

        return response()->json(['patient' => $this->transform($patient)]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'cpf' => ['required', 'string', 'max:20', 'unique:patients,cpf'],
            'name' => ['required', 'string', 'max:255'],
            'birthDate' => ['nullable', 'date'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        $patient = Patient::create([
            'cpf' => $data['cpf'],
            'name' => $data['name'],
            'birth_date' => $data['birthDate'] ?? null,
            'email' => $data['email'] ?? null,
        ]);

        return response()->json(['patient' => $this->transform($patient)], 201);
    }

    private function transform(Patient $patient): array
    {
        return [
            'id' => (string) $patient->id,
            'cpf' => $patient->cpf,
            'name' => $patient->name,
            'birthDate' => optional($patient->birth_date)->format('Y-m-d'),
            'email' => $patient->email,
        ];
    }
}
