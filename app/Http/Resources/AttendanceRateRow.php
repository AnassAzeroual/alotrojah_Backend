<?php

namespace App\Http\Resources;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * One row of the v_attendance_rate reporting view: a pupil's per-status
 * attendance counts for one term and the resulting presence percentage.
 *
 * Read through the query builder, so its DECIMAL columns arrive as strings
 * (PDO has no lossless numeric type for them) — the counts are SUM()s over
 * boolean flags, so they become ints rather than floats. Being an Arrayable
 * gives the OpenAPI spec one named schema for this row.
 */
final class AttendanceRateRow implements Arrayable, JsonSerializable
{
    public function __construct(private readonly object $row) {}

    /**
     * @return array{student_id: int, season_id: int, term_id: int, total_sessions: int, present_count: int|null, absent_count: int|null, late_count: int|null, excused_count: int|null, attendance_rate_pct: float|null}
     */
    public function toArray(): array
    {
        $r = $this->row;

        return [
            'student_id' => (int) $r->student_id,
            'season_id' => (int) $r->season_id,
            'term_id' => (int) $r->term_id,
            'total_sessions' => (int) $r->total_sessions,
            'present_count' => $r->present_count === null ? null : (int) $r->present_count,
            'absent_count' => $r->absent_count === null ? null : (int) $r->absent_count,
            'late_count' => $r->late_count === null ? null : (int) $r->late_count,
            'excused_count' => $r->excused_count === null ? null : (int) $r->excused_count,
            'attendance_rate_pct' => $r->attendance_rate_pct === null ? null : (float) $r->attendance_rate_pct,
        ];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
