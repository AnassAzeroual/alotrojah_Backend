<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Weekly-exam portion storage (user-directed 2026-10-16): the memorized
// portion rides the first (memorization) question row — surah mode already
// has surah_ref/ayah_from/ayah_to, thumn mode has hizb_ref but no thumn
// column, so it gains a nullable one. Purely additive: existing rows read
// NULL, rollback drops the column.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE `exam_questions` ADD COLUMN `thumn_ref` TINYINT UNSIGNED NULL DEFAULT NULL AFTER `hizb_ref`');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `exam_questions` DROP COLUMN `thumn_ref`');
    }
};
