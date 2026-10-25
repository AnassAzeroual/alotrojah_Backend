<?php

namespace App\Services;

use App\Models\Session;
use App\Models\Term;
use App\Models\TermResult;
use Illuminate\Support\Facades\DB;

/**
 * Term Hifz mark = average of the term's session totals.
 *
 * A session total is the sum of active weekly-total modules (default Hifz 14 +
 * Mowathaba 4 + Tajweed 2 = 20); Sarraj is a separate /20 track and excluded.
 * The denominator is every non-cancelled session in the term; a session with
 * no saved score contributes 0.
 */
final class TermHifzResultSync
{
    public function apply(int $studentId, int $termId): void
    {
        $term = Term::find($termId);
        if ($term === null) {
            return;
        }

        $sessionIds = Session::where('term_id', $termId)
            ->where('status', '!=', 'cancelled')
            ->pluck('id');
        $sessions = $sessionIds->count();
        if ($sessions === 0) {
            return;
        }

        $sum = DB::table('session_scores as sc')
            ->join('scoring_modules as mo', 'mo.id', '=', 'sc.module_id')
            ->where('sc.student_id', $studentId)
            ->whereIn('sc.session_id', $sessionIds)
            ->where('mo.is_active', 1)
            ->where('mo.is_in_weekly_total', 1)
            ->where('mo.scope', 'weekly')
            ->sum('sc.score');

        $hifz = min(20.0, round((float) $sum / $sessions, 2));

        $result = TermResult::firstOrNew([
            'student_id' => $studentId,
            'term_id' => $termId,
        ]);
        $result->season_id = $term->season_id;
        $result->hifz_total = $hifz;
        $result->save();
    }

    /** Recalculate a saved term result without touching its other fields. */
    public function applyExisting(TermResult $result): void
    {
        $this->apply((int) $result->student_id, (int) $result->term_id);
    }
}
