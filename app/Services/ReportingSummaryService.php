<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Maintains the reporting summary tables.
 *
 * Definitions (kept deliberately identical to the views):
 *  - session_totals: v_session_totals, one row per pupil/session/log_date
 *  - attendance_totals: v_attendance_rate, one row per pupil/season/term
 *  - student_separate_module_avgs: v_separate_module_avgs, one row per
 *    pupil/season/week/module for non-weekly-total modules (sarraj)
 *
 * Score/attendance writes refresh only the affected pupil lane. A scoring
 * module change affects every score (active / weekly-total flags), so it queues
 * one full rebuild after the surrounding transaction commits.
 */
final class ReportingSummaryService
{
    private static bool $rebuildQueued = false;

    /**
     * Refresh both score-derived summaries for one pupil/session.
     */
    public function refreshSessionScore(int $studentId, int $sessionId): void
    {
        $session = DB::table('sessions')
            ->where('id', $sessionId)
            ->first(['season_id', 'week_id']);
        if ($session === null) {
            return;
        }

        DB::transaction(function () use ($studentId, $sessionId, $session): void {
            $this->refreshSessionTotals($studentId, $sessionId);
            $this->refreshSeparateModuleAvgs(
                $studentId,
                (int) $session->season_id,
                (int) $session->week_id,
            );
        });
    }

    public function refreshSessionTotals(int $studentId, int $sessionId): void
    {
        DB::table('session_totals')
            ->where('student_id', $studentId)
            ->where('session_id', $sessionId)
            ->delete();

        $rows = DB::table('session_scores as sc')
            ->join('scoring_modules as mo', 'mo.id', '=', 'sc.module_id')
            ->where('sc.student_id', $studentId)
            ->where('sc.session_id', $sessionId)
            ->where('mo.is_active', 1)
            ->where('mo.is_in_weekly_total', 1)
            ->groupBy('sc.student_id', 'sc.season_id', 'sc.term_id', 'sc.week_id', 'sc.session_id', 'sc.log_date')
            ->selectRaw('sc.student_id, sc.season_id, sc.term_id, sc.week_id, sc.session_id, sc.log_date,
                ROUND(SUM(sc.score),1) AS total_score, COUNT(*) AS modules_scored')
            ->get();

        if ($rows->isNotEmpty()) {
            DB::table('session_totals')->insert(
                $rows->map(static fn (object $row): array => (array) $row)->all()
            );
        }
    }

    public function refreshSeparateModuleAvgs(int $studentId, int $seasonId, int $weekId): void
    {
        DB::table('student_separate_module_avgs')
            ->where('student_id', $studentId)
            ->where('season_id', $seasonId)
            ->where('week_id', $weekId)
            ->delete();

        $rows = DB::table('session_scores as sc')
            ->join('scoring_modules as mo', 'mo.id', '=', 'sc.module_id')
            ->where('sc.student_id', $studentId)
            ->where('sc.season_id', $seasonId)
            ->where('sc.week_id', $weekId)
            ->where('mo.is_active', 1)
            ->where('mo.is_in_weekly_total', 0)
            ->groupBy('sc.student_id', 'sc.season_id', 'sc.week_id', 'mo.code', 'mo.name_ar')
            ->selectRaw('sc.student_id, sc.season_id, sc.week_id, mo.code AS module_code,
                mo.name_ar AS module_name, ROUND(AVG(sc.score),2) AS avg_score, COUNT(*) AS sessions_scored')
            ->get();

        if ($rows->isNotEmpty()) {
            DB::table('student_separate_module_avgs')->insert(
                $rows->map(static fn (object $row): array => (array) $row)->all()
            );
        }
    }

    public function refreshAttendanceTotals(int $studentId, int $seasonId, int $termId): void
    {
        DB::transaction(function () use ($studentId, $seasonId, $termId): void {
            DB::table('attendance_totals')
                ->where('student_id', $studentId)
                ->where('season_id', $seasonId)
                ->where('term_id', $termId)
                ->delete();

            $row = DB::table('attendance')
                ->where('student_id', $studentId)
                ->where('season_id', $seasonId)
                ->where('term_id', $termId)
                ->selectRaw("COUNT(*) AS total_sessions,
                    SUM(status='present') AS present_count,
                    SUM(status='absent') AS absent_count,
                    SUM(status='late') AS late_count,
                    SUM(status='excused') AS excused_count,
                    ROUND(100*SUM(status IN ('present','late'))/COUNT(*),1) AS attendance_rate_pct")
                ->first();

            if ($row !== null && (int) $row->total_sessions > 0) {
                DB::table('attendance_totals')->insert([
                    'student_id' => $studentId,
                    'season_id' => $seasonId,
                    'term_id' => $termId,
                    'total_sessions' => (int) $row->total_sessions,
                    'present_count' => (int) $row->present_count,
                    'absent_count' => (int) $row->absent_count,
                    'late_count' => (int) $row->late_count,
                    'excused_count' => (int) $row->excused_count,
                    'attendance_rate_pct' => $row->attendance_rate_pct,
                ]);
            }
        });
    }

    /**
     * Drop and rebuild every summary row from the source tables.
     *
     * Used by the backfill/repair command and after a scoring-module change.
     *
     * @return array{session_totals: int, attendance_totals: int, student_separate_module_avgs: int}
     */
    public function rebuildAll(): array
    {
        DB::transaction(function (): void {
            DB::table('session_totals')->delete();
            DB::table('attendance_totals')->delete();
            DB::table('student_separate_module_avgs')->delete();

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
        });

        return [
            'session_totals' => DB::table('session_totals')->count(),
            'attendance_totals' => DB::table('attendance_totals')->count(),
            'student_separate_module_avgs' => DB::table('student_separate_module_avgs')->count(),
        ];
    }

    /**
     * Defer a full rebuild until the current transaction commits. A module
     * rebalance can touch several rows in one request; only one rebuild runs.
     */
    public function queueRebuild(): void
    {
        if (app()->runningUnitTests()) {
            $this->rebuildAll();

            return;
        }

        if (self::$rebuildQueued) {
            return;
        }

        self::$rebuildQueued = true;
        DB::afterCommit(function (): void {
            self::$rebuildQueued = false;
            app(self::class)->rebuildAll();
        });
    }
}
