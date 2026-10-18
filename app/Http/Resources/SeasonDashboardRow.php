<?php

namespace App\Http\Resources;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * One row of the v_season_dashboard reporting view: a pupil's season totals
 * against their plan, plus the honor flag from their season result.
 *
 * Read through the query builder, so its DECIMAL columns arrive as strings
 * (PDO has no lossless numeric type for them) while every Eloquent resource on
 * this API emits numbers — the casts below keep that contract. Being an
 * Arrayable gives the OpenAPI spec one named schema shared by the dashboard
 * endpoints that return this row.
 */
final class SeasonDashboardRow implements Arrayable, JsonSerializable
{
    public function __construct(private readonly object $row) {}

    /**
     * @return array{student_id: int, full_name: string, level_id: int|null, group_id: int|null, season_id: int, season_thumn: float|null, season_avg_score: float|null, season_avg_sarraj: float|null, season_attendance_pct: float|null, season_overall_avg: float|null, honor_flag: 'none'|'tashji3'|'intibah'|null}
     */
    public function toArray(): array
    {
        $r = $this->row;

        return [
            'student_id' => (int) $r->student_id,
            'full_name' => (string) $r->full_name,
            'level_id' => $r->level_id === null ? null : (int) $r->level_id,
            'group_id' => $r->group_id === null ? null : (int) $r->group_id,
            'season_id' => (int) $r->season_id,
            'season_thumn' => $r->season_thumn === null ? null : (float) $r->season_thumn,
            'season_avg_score' => $r->season_avg_score === null ? null : (float) $r->season_avg_score,
            'season_avg_sarraj' => $r->season_avg_sarraj === null ? null : (float) $r->season_avg_sarraj,
            'season_attendance_pct' => $r->season_attendance_pct === null ? null : (float) $r->season_attendance_pct,
            'season_overall_avg' => $r->season_overall_avg === null ? null : (float) $r->season_overall_avg,
            'honor_flag' => $r->honor_flag,
        ];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
