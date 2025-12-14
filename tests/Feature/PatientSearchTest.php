<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PatientSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_by_digits_matches_formatted_record(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $patient = Patient::create([
            'cpf' => '123.456.789-09',
            'name' => 'Maria Silva',
            'birth_date' => '1985-03-15',
            'email' => 'maria.silva@example.com',
        ]);

        $response = $this->getJson('/api/patients/search?cpf=12345678909');

        $response->assertOk()
            ->assertJsonPath('patient.id', (string) $patient->id)
            ->assertJsonPath('patient.cpf', '123.456.789-09');
    }

    public function test_search_returns_not_found_when_missing(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/patients/search?cpf=00000000000');

        $response->assertStatus(404);
    }
}
