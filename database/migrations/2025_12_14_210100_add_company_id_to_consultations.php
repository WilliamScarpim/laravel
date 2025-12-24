<?php

use App\Models\Consultation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('doctor_id')->constrained('users');
            $table->index('company_id');
        });

        // Backfill existing consultations with the company associated to the doctor, when available.
        Consultation::query()
            ->with('doctor')
            ->chunkById(100, function ($batch) {
                /** @var Consultation $consultation */
                foreach ($batch as $consultation) {
                    $companyId = $consultation->doctor?->company_id;
                    if ($companyId) {
                        $consultation->forceFill(['company_id' => $companyId])->saveQuietly();
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
