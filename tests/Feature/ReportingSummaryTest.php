<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\ScoringModule;
use App\Models\SessionScore;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The reporting summary tables are a materialized copy of the reporting views.
 * These tests guard the write-path maintenance and the module-change rebuild.
 */
class ReportingSummaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_score_writes_refresh_session_and_separate_module_summaries(): void
    {
        $score = SessionScore::query()->whereHas('module', function ($q) {
            $q->where('is_in_weekly_total', 1);
        })->firstOrFail();

        $original = (float) $score->score;
        $score->score = $original === 20.0 ? 19.0 : $original + 1.0;
        $score->save();

        $expectedTotal = (float) DB::table('session_scores as sc')
            ->join('scoring_modules as mo', 'mo.id', '=', 'sc.module_id')
            ->where('sc.student_id', $score->student_id)
            ->where('sc.session_id', $score->session_id)
            ->where('mo.is_active', 1)
            ->where('mo.is_in_weekly_total', 1)
            ->sum('sc.score');

        $summary = DB::table('session_totals')
            ->where('student_id', $score->student_id)
            ->where('session_id', $score->session_id)
            ->first();

        $this->assertNotNull($summary);
        $this->assertSame($expectedTotal, (float) $summary->total_score);

        $score->delete();

        $afterDelete = DB::table('session_totals')
            ->where('student_id', $score->student_id)
            ->where('session_id', $score->session_id)
            ->first();

        $this->assertNotNull($afterDelete);
        $this->assertLessThan($expectedTotal, (float) $afterDelete->total_score);

        $expectedSeparate = DB::table('session_scores as sc')
            ->join('scoring_modules as mo', 'mo.id', '=', 'sc.module_id')
            ->where('sc.student_id', $score->student_id)
            ->where('sc.season_id', $score->season_id)
            ->where('sc.week_id', $score->week_id)
            ->where('mo.is_active', 1)
            ->where('mo.is_in_weekly_total', 0)
            ->avg('sc.score');

        $this->assertSame(
            $expectedSeparate === null ? null : round((float) $expectedSeparate, 2),
            $this->separateAverage($score->student_id, $score->season_id, $score->week_id),
        );
    }

    public function test_attendance_writes_refresh_attendance_totals(): void
    {
        $attendance = Attendance::query()->firstOrFail();
        $original = $attendance->status;

        $attendance->status = $original === 'present' ? 'absent' : 'present';
        $attendance->save();

        $expected = DB::table('attendance')
            ->where('student_id', $attendance->student_id)
            ->where('season_id', $attendance->season_id)
            ->where('term_id', $attendance->term_id)
            ->selectRaw("COUNT(*) AS total_sessions,
                SUM(status='present') AS present_count,
                SUM(status='absent') AS absent_count,
                SUM(status='late') AS late_count,
                SUM(status='excused') AS excused_count,
                ROUND(100*SUM(status IN ('present','late'))/COUNT(*),1) AS attendance_rate_pct")
            ->first();

        $summary = DB::table('attendance_totals')
            ->where('student_id', $attendance->student_id)
            ->where('season_id', $attendance->season_id)
            ->where('term_id', $attendance->term_id)
            ->first();

        $this->assertNotNull($summary);
        foreach (['total_sessions', 'present_count', 'absent_count', 'late_count', 'excused_count'] as $column) {
            $this->assertSame((int) $expected->{$column}, (int) $summary->{$column});
        }
        $this->assertSame((float) $expected->attendance_rate_pct, (float) $summary->attendance_rate_pct);
    }

    public function test_rebuild_command_reproduces_every_legacy_view(): void
    {
        $this->artisan('reporting:rebuild')->assertExitCode(0);

        $this->assertSame(
            DB::table('v_session_totals')->count(),
            DB::table('session_totals')->count(),
        );
        $this->assertSame(
            (float) DB::table('v_session_totals')->sum('total_score'),
            (float) DB::table('session_totals')->sum('total_score'),
        );

        $this->assertSame(
            DB::table('v_attendance_rate')->count(),
            DB::table('attendance_totals')->count(),
        );
        $this->assertSame(
            (int) DB::table('v_attendance_rate')->sum('total_sessions'),
            (int) DB::table('attendance_totals')->sum('total_sessions'),
        );
        $this->assertSame(
            (int) DB::table('v_attendance_rate')->sum('present_count'),
            (int) DB::table('attendance_totals')->sum('present_count'),
        );

        $this->assertSame(
            DB::table('v_separate_module_avgs')->count(),
            DB::table('student_separate_module_avgs')->count(),
        );
        $this->assertSame(
            (float) DB::table('v_separate_module_avgs')->sum('avg_score'),
            (float) DB::table('student_separate_module_avgs')->sum('avg_score'),
        );
    }

    public function test_module_flag_changes_rebuild_all_score_summaries(): void
    {
        $module = ScoringModule::query()->where('is_in_weekly_total', 1)->where('is_active', 1)->firstOrFail();
        $module->is_active = false;
        $module->save();

        $this->assertSame(
            DB::table('v_session_totals')->count(),
            DB::table('session_totals')->count(),
        );
        $this->assertSame(
            (float) DB::table('v_session_totals')->sum('total_score'),
            (float) DB::table('session_totals')->sum('total_score'),
        );

        $module->is_active = true;
        $module->save();
    }

    private function separateAverage(int $studentId, int $seasonId, int $weekId): ?float
    {
        $value = DB::table('student_separate_module_avgs')
            ->where('student_id', $studentId)
            ->where('season_id', $seasonId)
            ->where('week_id', $weekId)
            ->where('module_code', 'sarraj')
            ->value('avg_score');

        return $value === null ? null : (float) $value;
    }
}
