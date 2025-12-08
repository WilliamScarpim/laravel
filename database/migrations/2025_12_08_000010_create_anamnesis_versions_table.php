<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('anamnesis_versions')) {
            return;
        }

        Schema::create('anamnesis_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('consultation_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignUlid('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->unsignedInteger('version');
            $table->longText('anamnesis')->nullable();
            $table->longText('transcription')->nullable();
            $table->string('summary')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anamnesis_versions');
    }
};
