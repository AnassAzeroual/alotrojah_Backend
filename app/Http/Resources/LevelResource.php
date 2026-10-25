<?php

namespace App\Http\Resources;

use App\Models\Level;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * One level row. `ahzab_per_term` is the owner-locked stored baseline;
 * `ahzab_first_term` and `term_goals` are computed from the season calendar
 * (study weeks only), so the goal follows the real term length.
 *
 * A plain Arrayable (like the reporting rows) so the OpenAPI spec gets one
 * named schema from the `@return` shape below — JsonResource inference reads
 * the array literal and types the computed columns as strings.
 */
final class LevelResource implements Arrayable, JsonSerializable
{
    /** @param  list<array{term_id:int, term_number:int, name_ar:string, study_weeks:int, review_weeks:int, ahzab:float}>  $termGoals */
    public function __construct(
        private readonly Level $level,
        private readonly array $termGoals = [],
    ) {}

    /**
     * @return array{
     *     id: int,
     *     season_id: int|null,
     *     center_id: int|null,
     *     code: string,
     *     name_ar: string,
     *     sessions_per_week: int,
     *     thumn_per_session_label: string,
     *     thumn_per_session_value: float,
     *     thumn_per_week_value: float,
     *     ahzab_per_term: float,
     *     ahzab_per_dawra: float,
     *     duration_label: string,
     *     total_ahzab: int,
     *     ahzab_first_term: float|null,
     *     term_goals: list<array{term_id: int, term_number: int, name_ar: string, study_weeks: int, review_weeks: int, ahzab: float}>
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => (int) $this->level->id,
            'season_id' => $this->level->season_id === null ? null : (int) $this->level->season_id,
            'center_id' => $this->level->center_id === null ? null : (int) $this->level->center_id,
            'code' => (string) $this->level->code,
            'name_ar' => (string) $this->level->name_ar,
            'sessions_per_week' => (int) $this->level->sessions_per_week,
            'thumn_per_session_label' => (string) $this->level->thumn_per_session_label,
            'thumn_per_session_value' => (float) $this->level->thumn_per_session_value,
            'thumn_per_week_value' => (float) $this->level->thumn_per_week_value,
            'ahzab_per_term' => (float) $this->level->ahzab_per_term,
            'ahzab_per_dawra' => (float) $this->level->ahzab_per_dawra,
            'duration_label' => (string) $this->level->duration_label,
            'total_ahzab' => (int) $this->level->total_ahzab,
            'ahzab_first_term' => $this->firstTermAhzab(),
            'term_goals' => $this->termGoals,
        ];
    }

    /** Headline goal: the first term's exact ahzab, null for a template row. */
    private function firstTermAhzab(): ?float
    {
        return $this->termGoals === [] ? null : (float) $this->termGoals[0]['ahzab'];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
