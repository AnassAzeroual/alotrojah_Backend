<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Dynamic level codes (user-directed 2026-10-14): `levels.code` was a fixed
// ENUM('L1','L2','L3') — new levels now get `L{id}` codes, so the column
// becomes a short string. Existing L1/L2/L3 rows are untouched; the
// (code, center_id) uniqueness and copy-on-write semantics are unchanged.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE `levels` MODIFY COLUMN `code` VARCHAR(10) NOT NULL');
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE `levels` MODIFY COLUMN `code` ENUM('L1','L2','L3') NOT NULL DEFAULT 'L1'");
    }
};
