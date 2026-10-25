<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Maintained read model for the reporting feeds.
 *
 * The reporting views aggregate every score/attendance row before a caller can
 * narrow the query. These tables keep the exact view definitions, but one row
 * per pupil/session (or pupil/term) so the hot feeds stay bounded by the
 * selected center/season instead of the whole school.
 *
 * They are maintained by ReportingSummaryService (model observers + bulk daily
 * entry) and can be rebuilt with `php artisan reporting:rebuild`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_totals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('season_id');
            $table->unsignedBigInteger('term_id');
            $table->unsignedBigInteger('week_id');
            $table->unsignedBigInteger('session_id');
            $table->date('log_date');
            $table->decimal('total_score', 5, 1);
            $table->unsignedInteger('modules_scored');
            $table->unique(
                ['student_id', 'session_id', 'log_date'],
                'uq_session_totals_student_session_date'
            );
            $table->index(['season_id', 'student_id'], 'idx_session_totals_season_student');
            $table->index(['season_id', 'term_id', 'student_id'], 'idx_session_totals_season_term_student');
            $table->foreign('student_id', 'fk_session_totals_student')
                ->references('id')->on('students')->onDelete('cascade');
            $table->foreign('season_id', 'fk_session_totals_season')
                ->references('id')->on('academic_seasons')->onDelete('cascade');
            $table->foreign('term_id', 'fk_session_totals_term')
                ->references('id')->on('terms')->onDelete('cascade');
            $table->foreign('week_id', 'fk_session_totals_week')
                ->references('id')->on('weeks')->onDelete('cascade');
            $table->foreign('session_id', 'fk_session_totals_session')
                ->references('id')->on('sessions')->onDelete('cascade');
        });

        Schema::create('attendance_totals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('season_id');
            $table->unsignedBigInteger('term_id');
            $table->unsignedInteger('total_sessions');
            $table->unsignedInteger('present_count');
            $table->unsignedInteger('absent_count');
            $table->unsignedInteger('late_count');
            $table->unsignedInteger('excused_count');
            $table->decimal('attendance_rate_pct', 5, 1);
            $table->unique(
                ['student_id', 'season_id', 'term_id'],
                'uq_attendance_totals_student_season_term'
            );
            $table->index(['season_id', 'term_id', 'student_id'], 'idx_attendance_totals_season_term_student');
            $table->index(['season_id', 'student_id'], 'idx_attendance_totals_season_student');
            $table->foreign('student_id', 'fk_attendance_totals_student')
                ->references('id')->on('students')->onDelete('cascade');
            $table->foreign('season_id', 'fk_attendance_totals_season')
                ->references('id')->on('academic_seasons')->onDelete('cascade');
            $table->foreign('term_id', 'fk_attendance_totals_term')
                ->references('id')->on('terms')->onDelete('cascade');
        });

        Schema::create('student_separate_module_avgs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('season_id');
            $table->unsignedBigInteger('week_id');
            $table->string('module_code', 40);
            $table->string('module_name', 120);
            $table->decimal('avg_score', 4, 2);
            $table->unsignedInteger('sessions_scored');
            $table->unique(
                ['student_id', 'season_id', 'week_id', 'module_code', 'module_name'],
                'uq_separate_avgs_student_season_week_module'
            );
            $table->index(
                ['season_id', 'module_code', 'student_id'],
                'idx_separate_avgs_season_module_student'
            );
            $table->foreign('student_id', 'fk_separate_avgs_student')
                ->references('id')->on('students')->onDelete('cascade');
            $table->foreign('season_id', 'fk_separate_avgs_season')
                ->references('id')->on('academic_seasons')->onDelete('cascade');
            $table->foreign('week_id', 'fk_separate_avgs_week')
                ->references('id')->on('weeks')->onDelete('cascade');
        });

        // Backfill in one statement per table: the tables are created after the
        // source data in every environment (fresh installs have none).
        DB::statement('INSERT INTO `session_totals`
            (student_id, season_id, term_id, week_id, session_id, log_date, total_score, modules_scored)
            SELECT sc.student_id, sc.season_id, sc.term_id, sc.week_id, sc.session_id, sc.log_date,
                ROUND(SUM(sc.score), 1) AS total_score, COUNT(*) AS modules_scored
            FROM `session_scores` sc
            JOIN `scoring_modules` mo
                ON mo.id = sc.module_id AND mo.is_active = 1 AND mo.is_in_weekly_total = 1
            GROUP BY sc.student_id, sc.season_id, sc.term_id, sc.week_id, sc.session_id, sc.log_date');

        DB::statement('INSERT INTO `attendance_totals`
            (student_id, season_id, term_id, total_sessions, present_count, absent_count,
             late_count, excused_count, attendance_rate_pct)
            SELECT a.student_id, a.season_id, a.term_id, COUNT(*) AS total_sessions,
                SUM(a.status = \'present\') AS present_count,
                SUM(a.status = \'absent\') AS absent_count,
                SUM(a.status = \'late\') AS late_count,
                SUM(a.status = \'excused\') AS excused_count,
                ROUND(100 * SUM(a.status IN (\'present\', \'late\')) / COUNT(*), 1) AS attendance_rate_pct
            FROM `attendance` a
            GROUP BY a.student_id, a.season_id, a.term_id');

        DB::statement('INSERT INTO `student_separate_module_avgs`
            (student_id, season_id, week_id, module_code, module_name, avg_score, sessions_scored)
            SELECT sc.student_id, sc.season_id, sc.week_id, mo.code AS module_code,
                mo.name_ar AS module_name, ROUND(AVG(sc.score), 2) AS avg_score, COUNT(*) AS sessions_scored
            FROM `session_scores` sc
            JOIN `scoring_modules` mo ON mo.id = sc.module_id
            WHERE mo.is_active = 1 AND mo.is_in_weekly_total = 0
            GROUP BY sc.student_id, sc.season_id, sc.week_id, mo.code, mo.name_ar');
    }

    public function down(): void
    {
        Schema::dropIfExists('student_separate_module_avgs');
        Schema::dropIfExists('attendance_totals');
        Schema::dropIfExists('session_totals');
    }
};
