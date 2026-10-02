<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Baseline schema, must match docs/database/quran_memorization_db.sql exactly.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surahs', function (Blueprint $table) {
            $table->unsignedSmallInteger('id')->comment('1-114');
            $table->string('name_ar', 60);
            $table->unsignedSmallInteger('ayahs_count');
            $table->primary('id');
        });

        Schema::create('quran_hizb_reference', function (Blueprint $table) {
            $table->unsignedTinyInteger('hizb_no');
            $table->unsignedTinyInteger('juz_no');
            $table->string('label_ar', 50);
            $table->primary('hizb_no');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quran_hizb_reference');
        Schema::dropIfExists('surahs');
    }
};
