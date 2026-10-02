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
        Schema::create('scoring_modules', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->comment('hifz / mowathaba / tajwid / sarraj ...');
            $table->string('name_ar', 120);
            $table->decimal('max_points', 4, 1)->comment('manager-adjustable');
            $table->enum('scope', ['weekly', 'murajaa'])->default('weekly');
            $table->boolean('is_active')->default(1)->comment('inactive = grayed in UI, excluded everywhere');
            $table->boolean('is_in_weekly_total')->default(1)->comment('0 = own separate /20 (sarraj)');
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique('code', 'uq_scoringmodules_code');
        });

        Schema::create('session_scores', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('season_id');
            $table->unsignedBigInteger('term_id');
            $table->unsignedBigInteger('week_id');
            $table->unsignedBigInteger('session_id');
            $table->date('log_date');
            $table->unsignedBigInteger('module_id');
            $table->decimal('score', 4, 1)->default(0)->comment('manual per-session input by hifz teacher');
            $table->unsignedBigInteger('entered_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['student_id', 'session_id', 'module_id'], 'uq_scores_student_session_module');
            $table->index(['student_id', 'week_id'], 'idx_scores_week');
            $table->index('module_id', 'idx_scores_module');
            $table->foreign('student_id', 'fk_scores_student')->references('id')->on('students')->onDelete('cascade');
            $table->foreign('session_id', 'fk_scores_session')->references('id')->on('sessions')->onDelete('cascade');
            $table->foreign('module_id', 'fk_scores_module')->references('id')->on('scoring_modules');
        });

        Schema::create('memorization_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('season_id');
            $table->unsignedBigInteger('term_id');
            $table->unsignedBigInteger('week_id');
            $table->unsignedBigInteger('session_id');
            $table->date('log_date');
            $table->enum('log_mode', ['thumn', 'surah'])->default('thumn');
            $table->decimal('hizb_no', 4, 1)->nullable();
            $table->unsignedTinyInteger('thumn_no')->nullable();
            $table->decimal('thumn_amount', 5, 2)->default(1.00);
            $table->decimal('hizb_from', 4, 1)->nullable();
            $table->decimal('hizb_to', 4, 1)->nullable();
            $table->unsignedSmallInteger('surah_from')->nullable();
            $table->unsignedSmallInteger('ayah_from')->nullable();
            $table->unsignedSmallInteger('surah_to')->nullable();
            $table->unsignedSmallInteger('ayah_to')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['student_id', 'session_id'], 'uq_memolog_student_session');
            $table->index('log_date', 'idx_memolog_date');
            $table->index(['student_id', 'week_id'], 'idx_memolog_week');
            $table->index(['student_id', 'term_id'], 'idx_memolog_term');
            $table->foreign('student_id', 'fk_memolog_student')->references('id')->on('students')->onDelete('cascade');
            $table->foreign('session_id', 'fk_memolog_session')->references('id')->on('sessions')->onDelete('cascade');
        });

        Schema::create('revision_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('season_id');
            $table->unsignedBigInteger('term_id');
            $table->unsignedBigInteger('week_id');
            $table->unsignedBigInteger('session_id');
            $table->date('log_date');
            $table->decimal('hizb_from', 4, 1)->nullable();
            $table->decimal('hizb_to', 4, 1)->nullable();
            $table->decimal('murajaa_score', 4, 1)->nullable()->comment('practice note /20 (official = murajaa_reviews)');
            $table->unsignedBigInteger('entered_by')->nullable();
            $table->index(['student_id', 'session_id'], 'idx_revlog_student_session');
            $table->index('log_date', 'idx_revlog_date');
            $table->foreign('student_id', 'fk_revlog_student')->references('id')->on('students')->onDelete('cascade');
            $table->foreign('session_id', 'fk_revlog_session')->references('id')->on('sessions')->onDelete('cascade');
            $table->foreign('entered_by', 'fk_revlog_enteredby')->references('id')->on('users');
        });

        Schema::create('murajaa_reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('season_id');
            $table->unsignedBigInteger('term_id');
            $table->unsignedSmallInteger('week_from');
            $table->unsignedSmallInteger('week_to');
            $table->unsignedTinyInteger('weeks_covered')->nullable()->comment('computed by API: week_to-week_from+1');
            $table->unsignedBigInteger('session_id')->nullable()->comment('review session in agenda');
            $table->decimal('hizb_from', 4, 1)->nullable();
            $table->decimal('hizb_to', 4, 1)->nullable();
            $table->unsignedSmallInteger('surah_from')->nullable();
            $table->unsignedSmallInteger('ayah_from')->nullable();
            $table->unsignedSmallInteger('surah_to')->nullable();
            $table->unsignedSmallInteger('ayah_to')->nullable();
            $table->decimal('score', 4, 1)->comment('official review note /20');
            $table->unsignedBigInteger('entered_by')->nullable()->comment('murajaa teacher');
            $table->date('reviewed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['student_id', 'term_id'], 'idx_murajaa_student_term');
            $table->foreign('student_id', 'fk_murajaa_student')->references('id')->on('students')->onDelete('cascade');
            $table->foreign('session_id', 'fk_murajaa_session')->references('id')->on('sessions')->onDelete('set null');
            $table->foreign('entered_by', 'fk_murajaa_enteredby')->references('id')->on('users');
        });

        DB::statement('ALTER TABLE `murajaa_reviews` ADD CONSTRAINT `chk_murajaa_span` CHECK (`week_to` >= `week_from` AND (`week_to`-`week_from`+1) BETWEEN 1 AND 3)');
        DB::statement('ALTER TABLE `murajaa_reviews` ADD CONSTRAINT `chk_murajaa_score` CHECK (`score` BETWEEN 0 AND 20)');

        Schema::create('attendance', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('season_id');
            $table->unsignedBigInteger('term_id');
            $table->unsignedBigInteger('week_id');
            $table->unsignedBigInteger('session_id');
            $table->enum('status', ['present', 'absent', 'late', 'excused'])->default('present');
            $table->unsignedBigInteger('marked_by')->nullable();
            $table->timestamp('marked_at')->useCurrent();
            $table->string('notes', 255)->nullable();
            $table->unique(['student_id', 'session_id'], 'uq_att_student_session');
            $table->index(['student_id', 'week_id'], 'idx_att_week');
            $table->index('status', 'idx_att_status');
            $table->foreign('student_id', 'fk_att_student')->references('id')->on('students')->onDelete('cascade');
            $table->foreign('session_id', 'fk_att_session')->references('id')->on('sessions')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance');
        Schema::dropIfExists('murajaa_reviews');
        Schema::dropIfExists('revision_logs');
        Schema::dropIfExists('memorization_logs');
        Schema::dropIfExists('session_scores');
        Schema::dropIfExists('scoring_modules');
    }
};
