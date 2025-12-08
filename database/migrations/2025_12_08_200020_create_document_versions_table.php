<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('document_versions')) {
            return;
        }

        Schema::create('document_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignUlid('consultation_id')->constrained('consultations')->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version');
            $table->string('type');
            $table->string('title');
            $table->longText('content');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_versions');
    }
};
