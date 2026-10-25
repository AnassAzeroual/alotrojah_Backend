<?php

namespace Tests\Concerns;

use App\Models\AcademicSeason;
use App\Models\Level;

/**
 * Builds the admin-created level set inside a season (there is no static seed
 * any more). Values mirror the old demo set so goal-math assertions stay
 * meaningful.
 */
trait SeasonLevelFixtures
{
    /**
     * A usable level id for a pupil of this center: its current season's set,
     * creating the set (or a season) when the fixture has none.
     */
    protected function currentLevelId(int $centerId, string $code = 'L1'): int
    {
        $season = AcademicSeason::currentFor($centerId);
        if ($season === null) {
            $season = AcademicSeason::create([
                'name' => 'Levels for center '.$centerId.' '.uniqid(),
                'start_date' => '2026-09-01',
                'total_weeks' => 0,
                'total_sessions' => 0,
                'is_current' => true,
                'center_id' => $centerId,
            ]);
        }
        if (Level::forSeason($season->id)->doesntExist()) {
            $this->seedSeasonLevels($season);
        }

        return (int) (Level::forSeason($season->id)->where('code', $code)->value('id')
            ?? Level::forSeason($season->id)->value('id'));
    }

    protected function seedSeasonLevels(AcademicSeason $season): void
    {
        $rows = [
            ['L1', 'Level 1', 1.25, 4.0, 2.0, 15.0, '4 years'],
            ['L2', 'Level 2', 1.0, 3.0, 2.25, 11.5, '5 years 2 months'],
            ['L3', 'Level 3', 0.66, 2.0, 1.5, 7.5, '8 years'],
        ];

        foreach ($rows as [$code, $name, $unit, $week, $term, $dawra, $duration]) {
            Level::create([
                'season_id' => $season->id,
                'center_id' => $season->center_id,
                'code' => $code,
                'name_ar' => $name,
                'sessions_per_week' => 3,
                'thumn_per_session_label' => 'thumn',
                'thumn_per_session_value' => $unit,
                'thumn_per_week_value' => $week,
                'ahzab_per_term' => $term,
                'ahzab_per_dawra' => $dawra,
                'duration_label' => $duration,
                'total_ahzab' => 60,
            ]);
        }
    }
}
