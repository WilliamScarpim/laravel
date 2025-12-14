<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('specialties', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->timestamps();
        });

        $names = [
            'Acupuntura',
            'Alergia e imunologia',
            'Anestesiologia',
            'Angiologia',
            'Cardiologia',
            'Cirurgia cardiovascular',
            'Cirurgia da mão',
            'Cirurgia de cabeça e pescoço',
            'Cirurgia do aparelho digestivo',
            'Cirurgia geral',
            'Cirurgia oncológica',
            'Cirurgia pediátrica',
            'Cirurgia plástica',
            'Cirurgia torácica',
            'Cirurgia vascular',
            'Clínica médica',
            'Coloproctologia',
            'Dermatologia',
            'Endocrinologia e metabologia',
            'Endoscopia',
            'Gastroenterologia',
            'Genética médica',
            'Geriatria',
            'Ginecologia e obstetrícia',
            'Hematologia e hemoterapia',
            'Homeopatia',
            'Infectologia',
            'Mastologia',
            'Medicina de emergência',
            'Medicina de família e comunidade',
            'Medicina do trabalho',
            'Medicina do tráfego',
            'Medicina esportiva',
            'Medicina física e reabilitação',
            'Medicina intensiva',
            'Medicina legal e perícia médica',
            'Medicina nuclear',
            'Medicina preventiva e social',
            'Nefrologia',
            'Neurocirurgia',
            'Neurologia',
            'Nutrologia',
            'Oftalmologia',
            'Oncologia clínica',
            'Ortopedia e traumatologia',
            'Otorrinolaringologia',
            'Patologia',
            'Patologia clínica/medicina laboratorial',
            'Pediatria',
            'Pneumologia',
            'Psiquiatria',
            'Radiologia e diagnóstico por imagem',
            'Radioterapia',
            'Reumatologia',
            'Urologia',
        ];

        DB::table('specialties')->insert(array_map(function ($name) {
            return [
                'name' => $name,
                'slug' => Str::slug($name),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }, $names));

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'specialty_id')) {
                $table->foreignId('specialty_id')
                    ->nullable()
                    ->after('crm')
                    ->constrained('specialties')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('users', 'rqe')) {
                $table->string('rqe', 30)->nullable()->after('specialty_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'specialty_id')) {
                $table->dropForeign(['specialty_id']);
                $table->dropColumn('specialty_id');
            }
            if (Schema::hasColumn('users', 'rqe')) {
                $table->dropColumn('rqe');
            }
        });

        Schema::dropIfExists('specialties');
    }
};
