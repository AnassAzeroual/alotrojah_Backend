<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Bounded building blocks for the reporting feeds.
 *
 * The reporting views aggregate the whole school before the caller can add a
 * center/student/season predicate. These builders read the maintained summary
 * tables (same definitions) so the hot feeds only touch the selected pupils.
 */
final class ReportingQueries
{
    /**
     * One row per pupil/session/log_date: the weekly /20 total.
     * Materialized form of v_session_totals.
     *
     * @param  list<int>  $studentIds
     */
    public static function sessionTotals(array $studentIds, int $seasonId, ?int $termId = null): Builder
    {
        $query = DB::table('session_totals as st')
            ->whereIn('st.student_id', $studentIds ?: [0])
            ->where('st.season_id', $seasonId);

        if ($termId !== null) {
            $query->where('st.term_id', $termId);
        }

        return $query->select(
            'st.student_id',
            'st.season_id',
            'st.term_id',
            'st.week_id',
            'st.session_id',
            'st.log_date',
            'st.total_score',
            'st.modules_scored',
        );
    }

    /**
     * Weekly separate-module average (sarraj). Materialized form of
     * v_separate_module_avgs, narrowed to the requested pupils/season.
     *
     * @param  list<int>  $studentIds
     */
    public static function sarrajWeekly(array $studentIds, int $seasonId): Builder
    {
        return DB::table('student_separate_module_avgs as x')
            ->whereIn('x.student_id', $studentIds ?: [0])
            ->where('x.season_id', $seasonId)
            ->where('x.module_code', 'sarraj')
            ->select('x.student_id', 'x.season_id', 'x.week_id', 'x.avg_score');
    }
}
