<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Copy-on-write center overrides (per owner decision): scoring modules and
// levels keep their shared default rows (center_id NULL); a center that needs
// different values gets its own rows (center_id = N), created automatically on
// first edit under that center's scope. Reads resolve the effective set:
// override wins, default fills the gaps. Historical scores keep pointing at
// the rows that were current when they were entered (never rewritten).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scoring_modules', function (Blueprint $table) {
            $table->dropUnique('uq_scoringmodules_code');
            $table->unsignedBigInteger('center_id')->nullable()->after('id')
                ->comment('NULL = shared default set');
            $table->unique(['code', 'center_id'], 'uq_modules_code_center');
            $table->index('center_id', 'idx_modules_center');
        });

        Schema::table('levels', function (Blueprint $table) {
            $table->dropUnique('uq_levels_code');
            $table->unsignedBigInteger('center_id')->nullable()->after('code')
                ->comment('NULL = shared default set');
            $table->unique(['code', 'center_id'], 'uq_levels_code_center');
            $table->index('center_id', 'idx_levels_center');
        });
    }

    public function down(): void
    {
        DB::table('scoring_modules')->whereNotNull('center_id')->delete();
        Schema::table('scoring_modules', function (Blueprint $table) {
            $table->dropUnique('uq_modules_code_center');
            $table->dropIndex('idx_modules_center');
            $table->dropColumn('center_id');
            $table->unique('code', 'uq_scoringmodules_code');
        });

        DB::table('levels')->whereNotNull('center_id')->delete();
        Schema::table('levels', function (Blueprint $table) {
            $table->dropUnique('uq_levels_code_center');
            $table->dropIndex('idx_levels_center');
            $table->dropColumn('center_id');
            $table->unique('code', 'uq_levels_code');
        });
    }
};
