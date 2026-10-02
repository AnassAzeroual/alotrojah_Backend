<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Baseline schema, must match docs/database/quran_memorization_db.sql exactly.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_seasons', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->string('hijri_year', 20)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->unsignedSmallInteger('total_weeks')->default(42);
            $table->unsignedSmallInteger('total_sessions')->default(126);
            $table->boolean('is_current')->default(0);
            $table->unique('name', 'uq_seasons_name');
        });

        Schema::create('terms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('season_id');
            $table->unsignedTinyInteger('term_number')->comment('1-6 default, N allowed');
            $table->string('name_ar', 50);
            $table->unsignedSmallInteger('start_week');
            $table->unsignedSmallInteger('end_week');
            $table->unsignedSmallInteger('start_session_no');
            $table->unsignedSmallInteger('end_session_no');
            $table->unique(['season_id', 'term_number'], 'uq_terms_season_no');
            $table->foreign('season_id', 'fk_terms_season')->references('id')->on('academic_seasons')->onDelete('cascade');
        });

        Schema::create('weeks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('season_id');
            $table->unsignedBigInteger('term_id');
            $table->unsignedSmallInteger('week_number_global');
            $table->unsignedSmallInteger('week_number_in_term');
            $table->enum('week_type', ['study', 'review'])->default('study')->comment('7th week = review+quiz');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->unique(['season_id', 'week_number_global'], 'uq_weeks_season_global');
            $table->index('term_id', 'idx_weeks_term');
            $table->foreign('season_id', 'fk_weeks_season')->references('id')->on('academic_seasons')->onDelete('cascade');
            $table->foreign('term_id', 'fk_weeks_term')->references('id')->on('terms')->onDelete('cascade');
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('season_id');
            $table->unsignedBigInteger('term_id');
            $table->unsignedBigInteger('week_id');
            $table->unsignedSmallInteger('session_number_global');
            $table->unsignedTinyInteger('session_number_in_week');
            $table->enum('session_type', ['memorization', 'revision', 'exam'])->default('memorization');
            $table->date('planned_date')->nullable();
            $table->enum('status', ['planned', 'done', 'cancelled'])->default('planned');
            $table->unique(['season_id', 'session_number_global'], 'uq_sessions_season_global');
            $table->index('week_id', 'idx_sessions_week');
            $table->index('term_id', 'idx_sessions_term');
            $table->foreign('season_id', 'fk_sessions_season')->references('id')->on('academic_seasons')->onDelete('cascade');
            $table->foreign('term_id', 'fk_sessions_term')->references('id')->on('terms')->onDelete('cascade');
            $table->foreign('week_id', 'fk_sessions_week')->references('id')->on('weeks')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('weeks');
        Schema::dropIfExists('terms');
        Schema::dropIfExists('academic_seasons');
    }
};
