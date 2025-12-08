<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('consultation_audit_logs')) {
            return;
        }

        Schema::create('consultation_audit_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('consultation_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignUlid('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('action')->default('update');
            $table->json('changes');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_audit_logs');
    }
};
