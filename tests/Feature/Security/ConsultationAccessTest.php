<?php

namespace Tests\Feature\Security;

use App\Models\Consultation;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsultationAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_cannot_create_consultation(): void
    {
        $company = User::factory()->create(['role' => 'company']);
        $patient = Patient::create([
            'cpf' => '12345678901',
            'name' => 'Paciente Teste',
            'birth_date' => '1980-01-01',
            'email' => 'paciente@example.com',
        ]);

        $this->actingAs($company)
            ->postJson('/api/consultations', [
                'patientId' => $patient->id,
            ])
            ->assertStatus(403);
    }

    public function test_company_can_view_consultations_of_their_team(): void
    {
        $company = User::factory()->create(['role' => 'company']);
        $doctor = User::factory()->create([
            'role' => 'doctor',
            'company_id' => $company->id,
        ]);
        $patient = Patient::create([
            'cpf' => '10987654321',
            'name' => 'Paciente Empresa',
            'birth_date' => '1975-05-05',
            'email' => 'paciente2@example.com',
        ]);

        $consultation = Consultation::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'company_id' => $company->id,
            'date' => now()->toDateString(),
            'status' => 'completed',
            'current_step' => 'complete',
            'summary' => 'Resumo teste',
            'anamnesis' => 'Anamnese teste',
        ]);

        $this->actingAs($company)
            ->getJson("/api/consultations/{$consultation->id}")
            ->assertOk()
            ->assertJsonFragment(['id' => $consultation->id]);
    }

    public function test_company_cannot_view_other_company_consultation(): void
    {
        $companyA = User::factory()->create(['role' => 'company']);
        $companyB = User::factory()->create(['role' => 'company']);
        $doctor = User::factory()->create([
            'role' => 'doctor',
            'company_id' => $companyA->id,
        ]);
        $patient = Patient::create([
            'cpf' => '22222222222',
            'name' => 'Paciente Restrito',
            'birth_date' => '1990-02-02',
            'email' => 'paciente3@example.com',
        ]);

        $consultation = Consultation::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'company_id' => $companyA->id,
            'date' => now()->toDateString(),
            'status' => 'completed',
            'current_step' => 'complete',
            'summary' => 'Resumo teste',
            'anamnesis' => 'Anamnese teste',
        ]);

        $this->actingAs($companyB)
            ->getJson("/api/consultations/{$consultation->id}")
            ->assertStatus(403);
    }
}
