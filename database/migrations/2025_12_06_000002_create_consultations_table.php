<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('doctor_id')->constrained('users');
            $table->foreignUlid('patient_id')->constrained('patients');
            $table->date('date')->nullable();
            $table->string('summary')->nullable();
            $table->longText('transcription')->nullable();
            $table->string('status')->default('draft'); // draft, recording, transcription, documents, completed
            $table->string('current_step')->default('patient'); // aligns with frontend steps
            $table->json('metadata')->nullable(); // for AI flags/questions if needed later
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultations');
    }
};
