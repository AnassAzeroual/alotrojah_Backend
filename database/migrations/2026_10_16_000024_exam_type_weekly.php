<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Weekly exams (user-directed 2026-10-16): `exams.exam_type` gains
// `weekly` alongside hizb_completion/term_batch/final_season. A weekly exam
// is season-scoped but term-less (same shape as final_season); it never
// enters the final math (ScoringService/Dashboard only count
// term_batch+final_season — untouched).
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `exams` MODIFY COLUMN `exam_type` ENUM('hizb_completion','term_batch','final_season','weekly') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE `exams` MODIFY COLUMN `exam_type` ENUM('hizb_completion','term_batch','final_season') NOT NULL");
    }
};
