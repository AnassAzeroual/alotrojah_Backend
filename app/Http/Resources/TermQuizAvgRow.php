<?php

namespace App\Http\Resources;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * One row of the v_term_quiz_avgs reporting view: the summed score of every
 * question of one exam type in one term — the divisor inputs of the final
 * average formula.
 *
 * Read through the query builder, so its DECIMAL columns arrive as strings
 * (PDO has no lossless numeric type for them) while every Eloquent resource on
 * this API emits numbers — the casts below keep that contract. Being an
 * Arrayable gives the OpenAPI spec one named schema shared by the dashboard
 * and report endpoints that return these rows.
 */
final class TermQuizAvgRow implements Arrayable, JsonSerializable
{
    public function __construct(private readonly object $row) {}

    /**
     * @return array{student_id: int, season_id: int, term_id: int|null, exam_type: 'hizb_completion'|'term_batch'|'final_season'|'weekly', avg_score: float|null, questions_count: int}
     */
    public function toArray(): array
    {
        $r = $this->row;

        return [
            'student_id' => (int) $r->student_id,
            'season_id' => (int) $r->season_id,
            'term_id' => $r->term_id === null ? null : (int) $r->term_id,
            'exam_type' => $r->exam_type,
            'avg_score' => $r->avg_score === null ? null : (float) $r->avg_score,
            'questions_count' => (int) $r->questions_count,
        ];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
