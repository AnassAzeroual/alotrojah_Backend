<?php

namespace App\Services;

use App\Models\MurajaaReview;
use App\Models\Term;
use App\Models\TermResult;

/**
 * Term Murajaa mark = average of the official cycle reviews (`murajaa_reviews`)
 * for that student + term. Other term result fields are left untouched.
 */
final class TermMurajaaResultSync
{
    public function apply(int $studentId, int $termId): void
    {
        $term = Term::find($termId);
        if ($term === null) {
            return;
        }

        $average = MurajaaReview::where('student_id', $studentId)
            ->where('term_id', $termId)
            ->avg('score');
        $value = $average !== null ? round((float) $average, 2) : null;

        $result = TermResult::firstOrNew([
            'student_id' => $studentId,
            'term_id' => $termId,
        ]);
        if (! $result->exists && $value === null) {
            return;
        }

        $result->season_id = $term->season_id;
        $result->murajaa_total = $value;
        $result->save();
    }
}
