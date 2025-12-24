<?php

namespace App\Http\Controllers;

use App\Models\Specialty;
use App\Models\User;
use App\Services\AccountActivationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CompanyDoctorController extends Controller
{
    public function __construct(private readonly AccountActivationService $activationService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $company = $request->user();

        $doctors = $company->doctors()
            ->with('specialtyRelation')
            ->orderBy('name')
            ->get();

        return response()->json([
            'doctors' => $doctors->map(fn (User $doctor) => $this->transformDoctor($doctor))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $company = $request->user();

        if ($request->filled('crm')) {
            $request->merge([
                'crm' => Str::upper(trim((string) $request->input('crm'))),
            ]);
        }

        $crmPattern = '/^[0-9]{4,10}(?:-[0-9])?\/[A-Za-z]{2}$/';

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'crm' => ['required', 'string', 'max:20', "regex:{$crmPattern}"],
            'specialtyId' => ['nullable', 'integer', 'exists:specialties,id'],
            'rqe' => ['nullable', 'string', 'max:30'],
        ]);

        $specialtyId = $data['specialtyId'] ?? null;
        $specialty = $specialtyId ? Specialty::find($specialtyId) : null;
        $crm = strtoupper($data['crm']);

        $doctor = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => 'doctor',
            'crm' => $crm,
            'specialty' => $specialty?->name,
            'specialty_id' => $specialty?->id,
            'rqe' => $specialty ? $data['rqe'] ?? null : null,
            'company_id' => $company->id,
            'is_active' => false,
        ]);

        $doctor->load('specialtyRelation');
        $this->activationService->send($doctor);

        return response()->json([
            'doctor' => $this->transformDoctor($doctor),
        ], 201);
    }

    private function transformDoctor(User $doctor): array
    {
        return [
            'id' => (string) $doctor->id,
            'name' => $doctor->name,
            'email' => $doctor->email,
            'crm' => $doctor->crm,
            'specialty' => $doctor->specialtyRelation?->name ?? $doctor->specialty,
            'specialtyId' => $doctor->specialtyRelation?->id,
            'rqe' => $doctor->rqe,
            'isActive' => (bool) $doctor->is_active,
        ];
    }
}
