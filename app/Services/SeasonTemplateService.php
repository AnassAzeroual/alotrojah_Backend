<?php

namespace App\Services;

use App\Models\AcademicSeason;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Generates an editable season calendar. Default template:
 * N terms x 7 weeks (6 study + last week(s) review) — C-c decision.
 * Everything is rows (no constants in DB); the 42-week default lives here.
 */
class SeasonTemplateService
{
    /**
     * @param array $terms e.g. [['name'=>'الفصل الأول','weeks'=>7], ...] (default 6x7)
     */
    public function createSeason(
        string $name,
        string $startDate,
        array $terms = [],
        int $sessionsPerWeek = 3,
        int $reviewWeeksPerTerm = 1,
        ?string $hijriYear = null,
    ): AcademicSeason {
        if ($terms === []) {
            $names = ['الفصل الأول','الفصل الثاني','الفصل الثالث','الفصل الرابع','الفصل الخامس','الفصل السادس'];
            $terms = array_map(fn ($n) => ['name' => $n, 'weeks' => 7], $names);
        }

        return DB::transaction(function () use ($name, $startDate, $terms, $sessionsPerWeek, $reviewWeeksPerTerm, $hijriYear) {
            $totalWeeks = array_sum(array_column($terms, 'weeks'));
            $season = AcademicSeason::create([
                'name' => $name, 'hijri_year' => $hijriYear,
                'start_date' => $startDate,
                'total_weeks' => $totalWeeks,
                'total_sessions' => $totalWeeks * $sessionsPerWeek,
                'is_current' => false,
            ]);

            $weekNo = 0; $sessionNo = 0; $day = Carbon::parse($startDate);
            foreach ($terms as $i => $t) {
                $term = $season->terms()->create([
                    'term_number' => $i + 1, 'name_ar' => $t['name'],
                    'start_week' => $weekNo + 1, 'end_week' => $weekNo + $t['weeks'],
                    'start_session_no' => $sessionNo + 1,
                    'end_session_no' => $sessionNo + $t['weeks'] * $sessionsPerWeek,
                ]);
                for ($w = 1; $w <= $t['weeks']; $w++) {
                    $weekNo++;
                    $isReview = $w > $t['weeks'] - $reviewWeeksPerTerm;
                    $week = $term->weeks()->create([
                        'season_id' => $season->id,
                        'week_number_global' => $weekNo,
                        'week_number_in_term' => $w,
                        'week_type' => $isReview ? 'review' : 'study',
                        'start_date' => $day->toDateString(),
                        'end_date' => $day->copy()->addDays(6)->toDateString(),
                    ]);
                    for ($k = 1; $k <= $sessionsPerWeek; $k++) {
                        $sessionNo++;
                        $week->sessions()->create([
                            'season_id' => $season->id, 'term_id' => $term->id,
                            'session_number_global' => $sessionNo,
                            'session_number_in_week' => $k,
                            'session_type' => $isReview ? ($k < $sessionsPerWeek ? 'revision' : 'exam') : 'memorization',
                            'planned_date' => $day->copy()->addDays($k - 1)->toDateString(),
                            'status' => 'planned',
                        ]);
                    }
                    $day->addWeek();
                }
            }

            return $season;
        });
    }
}
