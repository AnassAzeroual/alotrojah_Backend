<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Placeholder default for the two-step dynamic-code create
// (LevelController@store inserts, then stamps `L{id}` in the same
// transaction). The value never escapes the transaction; it only keeps
// the NOT NULL column valid between the two writes.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `levels` MODIFY COLUMN `code` VARCHAR(10) NOT NULL DEFAULT 'L0'");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `levels` MODIFY COLUMN `code` VARCHAR(10) NOT NULL');
    }
};
