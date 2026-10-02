<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Baseline schema, must match docs/database/quran_memorization_db.sql exactly.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('term_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('season_id');
            $table->unsignedBigInteger('term_id');
            $table->text('goal_text')->nullable();
            $table->enum('plan_mode', ['thumn', 'surah'])->default('thumn');
            $table->decimal('start_hizb', 4, 1)->nullable();
            $table->decimal('end_hizb', 4, 1)->nullable();
            $table->unsignedSmallInteger('plan_surah_from')->nullable();
            $table->unsignedSmallInteger('plan_ayah_from')->nullable();
            $table->unsignedSmallInteger('plan_surah_to')->nullable();
            $table->unsignedSmallInteger('plan_ayah_to')->nullable();
            $table->decimal('expected_hifz_week_thumn', 5, 2)->nullable();
            $table->decimal('expected_hifz_term_ahzab', 5, 2)->nullable();
            $table->decimal('expected_hifz_season_ahzab', 5, 2)->nullable();
            $table->string('khatm_expected_at', 100)->nullable();
            $table->unique(['student_id', 'term_id'], 'uq_termplans_student_term');
            $table->index('season_id', 'idx_termplans_season');
            $table->foreign('student_id', 'fk_termplans_student')->references('id')->on('students')->onDelete('cascade');
            $table->foreign('season_id', 'fk_termplans_season')->references('id')->on('academic_seasons')->onDelete('cascade');
            $table->foreign('term_id', 'fk_termplans_term')->references('id')->on('terms')->onDelete('cascade');
        });

        DB::statement('ALTER TABLE `term_plans` ADD CONSTRAINT `chk_termplans_range` CHECK (`end_hizb` IS NULL OR `start_hizb` IS NULL OR `end_hizb` >= `start_hizb`)');

        Schema::create('weekly_goals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('season_id');
            $table->unsignedBigInteger('week_id');
            $table->string('target_text', 300)->nullable();
            $table->boolean('is_completed')->nullable();
            $table->unsignedBigInteger('checked_by')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->unique(['student_id', 'week_id'], 'uq_weeklygoal_student_week');
            $table->foreign('student_id', 'fk_wgoal_student')->references('id')->on('students')->onDelete('cascade');
            $table->foreign('week_id', 'fk_wgoal_week')->references('id')->on('weeks')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_goals');
        Schema::dropIfExists('term_plans');
    }
};
