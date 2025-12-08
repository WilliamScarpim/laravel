<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('anamnesis_versions')) {
            DB::statement('ALTER TABLE `anamnesis_versions` MODIFY `summary` LONGTEXT NULL');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('anamnesis_versions')) {
            DB::statement('ALTER TABLE `anamnesis_versions` MODIFY `summary` VARCHAR(255) NULL');
        }
    }
};
