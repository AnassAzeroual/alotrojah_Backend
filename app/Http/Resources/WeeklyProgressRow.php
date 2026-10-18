<?php

namespace App\Http\Resources;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * One row of the v_weekly_progress reporting view: a pupil's memorization and
 * scoring totals for a single week.
 *
 * The view is read through the query builder, so its DECIMAL columns arrive as
 * strings (PDO has no lossless numeric type for them) while every Eloquent
 * resource on this API emits numbers — the casts below keep that contract.
 * Being an Arrayable gives the OpenAPI spec one named schema shared by the
 * dashboard and report endpoints that feed these rows to Chart.js.
 */
final class WeeklyProgressRow implements Arrayable, JsonSerializable
{
    public function __construct(private readonly object $row) {}

    /**
     * @return array{student_id: int, season_id: int, term_id: int, week_id: int, total_thumn: float, total_ahzab: float, avg_score: float|null, sessions_logged: int}
     */
    public function toArray(): array
    {
        $r = $this->row;

        return [
            'student_id' => (int) $r->student_id,
            'season_id' => (int) $r->season_id,
            'term_id' => (int) $r->term_id,
            'week_id' => (int) $r->week_id,
            'total_thumn' => (float) $r->total_thumn,
            'total_ahzab' => (float) $r->total_ahzab,
            'avg_score' => $r->avg_score === null ? null : (float) $r->avg_score,
            'sessions_logged' => (int) $r->sessions_logged,
        ];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
