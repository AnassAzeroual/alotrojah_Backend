<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\MurajaaReview;
use App\Models\ScoringModule;
use App\Models\SessionScore;
use Illuminate\Support\Facades\DB;

/**
 * All scoring math. Rules (locked, questions.md):
 * - weekly total /20 = SUM of ACTIVE weekly-total modules (default 14+4+2)
 * - sarraj (R2): per-session input, own separate /20 track
 * - final = (avg_murajaa + avg_weekly + SUM(term quiz avgs incl. final)) / (2 + n_terms)
 */
class ScoringService
{
    /** Weekly /20 total for one student+session (sarraj excluded by design). */
    public function weeklyTotal(int $studentId, int $sessionId): float
    {
        return (float) SessionScore::query()
            ->where('student_id', $studentId)->where('session_id', $sessionId)
            ->whereHas('module', fn ($q) => $q->where('is_active', true)->where('is_in_weekly_total', true))
            ->sum('score');
    }

    /** Manager guard: active weekly-total modules must sum to exactly 20 (per set). */
    public function scoringCheck(?int $centerId = null): array
    {
        $total = (float) ScoringModule::effectiveFor($centerId)
            ->where('is_active', true)
            ->where('is_in_weekly_total', true)->where('scope', 'weekly')->sum('max_points');

        return ['valid' => abs($total - 20.0) < 0.001, 'total' => $total];
    }

    /** Season inputs for the final formula + sarraj track. */
    public function seasonAvgs(int $studentId, int $seasonId): array
    {
        $avgMurajaa = MurajaaReview::where('student_id', $studentId)->where('season_id', $seasonId)->avg('score');
        // average of per-session totals (not of raw rows)
        $avgWeekly = DB::table('session_scores as sc')
            ->join('scoring_modules as mo', 'mo.id', '=', 'sc.module_id')
            ->where('sc.student_id', $studentId)->where('sc.season_id', $seasonId)
            ->where('mo.is_active', true)->where('mo.is_in_weekly_total', true)
            ->groupBy('sc.session_id')->selectRaw('SUM(sc.score) as total')
            ->get()->avg('total');
        $avgSarraj = SessionScore::query()
            ->where('student_id', $studentId)->where('season_id', $seasonId)
            ->whereHas('module', fn ($q) => $q->where('code', 'sarraj')->where('is_active', true))
            ->avg('score');

        return [
            'avg_murajaa' => $avgMurajaa !== null ? round((float) $avgMurajaa, 2) : null,
            'avg_weekly' => $avgWeekly !== null ? round((float) $avgWeekly, 2) : null,
            'avg_sarraj' => $avgSarraj !== null ? round((float) $avgSarraj, 2) : null,
        ];
    }

    /**
     * Final average. Returns null when inputs are missing.
     * final = (avg_murajaa + avg_weekly + SUM(term quiz avgs incl. final)) / (2 + n)
     */
    public function finalAverage(int $studentId, int $seasonId): ?float
    {
        $avgs = $this->seasonAvgs($studentId, $seasonId);
        if ($avgs['avg_murajaa'] === null || $avgs['avg_weekly'] === null) return null;

        $quizzes = Exam::where('student_id', $studentId)->where('season_id', $seasonId)
            ->whereIn('exam_type', ['term_batch', 'final_season'])->get()
            ->map(fn ($e) => $e->questions()->whereNotNull('score')->exists()
                ? round((float) $e->questions()->whereNotNull('score')->sum('score'), 2)
                : null)
            ->filter(fn ($v) => $v !== null)->values();
        if ($quizzes->isEmpty()) return null;

        $n = $quizzes->count();

        return round(($avgs['avg_murajaa'] + $avgs['avg_weekly'] + $quizzes->sum()) / (2 + $n), 2);
    }
}
