<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('consultation_jobs')) {
            return;
        }

        Schema::create('consultation_jobs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('consultation_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('type');
            $table->string('status')->default('queued');
            $table->string('current_step')->nullable();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedInteger('queue_position')->default(0);
            $table->string('job_uuid')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_jobs');
    }
};
