<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Center-scoped seasons (per owner decision): seasons belong to exactly one
// center (NULL = pre-scoping legacy rows, shared). Plus: drop the unused
// `quran_hizb_reference` table (no endpoint, no UI, nothing reads it).
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('quran_hizb_reference');

        Schema::table('academic_seasons', function (Blueprint $table) {
            $table->unsignedBigInteger('center_id')->nullable()->after('id')
                ->comment('NULL = legacy shared season');
            $table->index('center_id', 'idx_seasons_center');
        });
    }

    public function down(): void
    {
        Schema::table('academic_seasons', function (Blueprint $table) {
            $table->dropIndex('idx_seasons_center');
            $table->dropColumn('center_id');
        });

        Schema::create('quran_hizb_reference', function (Blueprint $table) {
            $table->unsignedTinyInteger('hizb_no');
            $table->unsignedTinyInteger('juz_no');
            $table->string('label_ar', 50);
            $table->primary('hizb_no');
        });
        for ($n = 1; $n <= 60; $n++) {
            DB::table('quran_hizb_reference')->insertOrIgnore([
                'hizb_no' => $n,
                'juz_no' => (int) ceil($n / 2),
                'label_ar' => 'الحزب ' . $n,
            ]);
        }
    }
};
