<?php

namespace Database\Seeders;

use App\Models\Consultation;
use App\Models\Document;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $doctor = User::firstOrCreate(
            ['email' => 'medico@example.com'],
            [
                'name' => 'Dr. Ana Paula Mendes',
                'password' => bcrypt('password'),
                'role' => 'doctor',
                'crm' => 'CRM/SP 123456',
                'specialty' => 'Clínica Geral',
            ]
        );

        $patients = collect([
            ['cpf' => '123.456.789-09', 'name' => 'Maria Silva Santos', 'birth_date' => '1985-03-15', 'email' => 'maria.silva@email.com'],
            ['cpf' => '987.654.321-00', 'name' => 'João Pedro Oliveira', 'birth_date' => '1972-08-22', 'email' => 'joao.pedro@email.com'],
            ['cpf' => '456.789.123-45', 'name' => 'Ana Carolina Lima', 'birth_date' => '1990-11-30', 'email' => 'ana.lima@email.com'],
        ])->map(fn ($data) => Patient::firstOrCreate(['cpf' => $data['cpf']], $data));

        $firstPatient = $patients->first();

        if ($firstPatient) {
            $consultation = Consultation::firstOrCreate(
                ['patient_id' => $firstPatient->id, 'doctor_id' => $doctor->id, 'date' => now()->toDateString()],
                [
                    'summary' => 'Cefaleia tensional episódica',
                    'transcription' => '## Queixa Principal
Dor de cabeça persistente...',
                    'status' => 'completed',
                    'current_step' => 'complete',
                ]
            );

            $consultation->documents()->firstOrCreate(
                ['type' => 'prescription', 'title' => 'Receituário'],
                ['content' => 'Dipirona 500mg - tomar de 6/6h se dor.']
            );
        }
    }
}
