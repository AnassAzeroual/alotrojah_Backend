<?php

namespace App\Services;

use App\Models\AcademicSeason;
use App\Models\Group;
use App\Models\Term;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Generates an editable season calendar. Default template:
 * N terms x 7 weeks (6 study + last week(s) review) — C-c decision.
 * Everything is rows (no constants in DB); the 42-week default lives here.
 *
 * Per-group sessions (000020): every active group gets its own session set
 * on THAT group's weekdays (21:00-23:00 default) — no session ever serves
 * nobody. Weeks stay shared; only sessions fan out. Session numbers restart
 * per (season, group) — display only, enforced by uq_sessions_group_global.
 */
class SeasonTemplateService
{
    private const ORDER = ['Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6, 'Sun' => 7];

    /** Session child tables: a session with any of these is recorded history. */
    private const SESSION_CHILDREN = [
        'session_scores', 'attendance', 'murajaa_reviews', 'revision_logs', 'memorization_logs',
    ];

    /**
     * @param  array  $terms  e.g. [['name'=>'الفصل الأول','weeks'=>7], ...] (default 6x7)
     */
    public function createSeason(
        string $name,
        string $startDate,
        array $terms = [],
        int $reviewWeeksPerTerm = 1,
        ?string $hijriYear = null,
        ?int $centerId = null,
        string $defaultStart = '21:00:00',
        string $defaultEnd = '23:00:00',
    ): AcademicSeason {
        if ($terms === []) {
            $names = ['الفصل الأول', 'الفصل الثاني', 'الفصل الثالث', 'الفصل الرابع', 'الفصل الخامس', 'الفصل السادس'];
            $terms = array_map(fn ($n) => ['name' => $n, 'weeks' => 7], $names);
        }

        return DB::transaction(function () use ($name, $startDate, $terms, $reviewWeeksPerTerm, $hijriYear, $centerId, $defaultStart, $defaultEnd) {
            $totalWeeks = array_sum(array_column($terms, 'weeks'));
            $season = AcademicSeason::create([
                'name' => $name, 'hijri_year' => $hijriYear,
                'start_date' => $startDate, 'center_id' => $centerId,
                'total_weeks' => $totalWeeks,
                'total_sessions' => 0, // counted below, per group
                'is_current' => false,
            ]);

            $weekNo = 0;
            $day = Carbon::parse($startDate);
            foreach ($terms as $i => $t) {
                $term = $season->terms()->create([
                    'term_number' => $i + 1, 'name_ar' => $t['name'],
                    'start_week' => $weekNo + 1, 'end_week' => $weekNo + $t['weeks'],
                    'start_session_no' => 0, 'end_session_no' => 0,
                ]);
                for ($w = 1; $w <= $t['weeks']; $w++) {
                    $weekNo++;
                    $isReview = $w > $t['weeks'] - $reviewWeeksPerTerm;
                    $term->weeks()->create([
                        'season_id' => $season->id,
                        'week_number_global' => $weekNo,
                        'week_number_in_term' => $w,
                        'week_type' => $isReview ? 'review' : 'study',
                        'start_date' => $day->toDateString(),
                        'end_date' => $day->copy()->addDays(6)->toDateString(),
                    ]);
                    $day->addWeek();
                }
            }

            $total = 0;
            foreach ($this->seasonGroups($season) as $group) {
                $total += $this->generateForGroup($season->fresh(), $group, $defaultStart, $defaultEnd);
            }
            $season->update(['total_sessions' => $total]);

            return $season;
        });
    }

    /**
     * Build one group's session set for a season (creation backfill for
     * groups born after their seasons, and reactivation). The group owns its
     * days: every picked weekday gets a session each week (owner-directed
     * 2026-10-15 — the old season-cap slice is gone). $fromDate skips older
     * dates (day-edit rebuilds); null keeps the legacy whole-season grain
     * for creates and backfills. Returns row count.
     */
    public function generateForGroup(
        AcademicSeason $season,
        Group $group,
        string $defaultStart = '21:00:00',
        string $defaultEnd = '23:00:00',
        ?string $fromDate = null,
    ): int {
        $days = $this->groupDays($group);
        if ($days === []) {
            return 0;
        }

        $made = 0;
        $season->load(['terms.weeks']);
        $taken = DB::table('sessions')->where('group_id', $group->id)->pluck('planned_date')->map(
            fn ($d) => substr((string) $d, 0, 10)
        )->all();
        foreach ($season->terms as $term) {
            foreach ($term->weeks as $week) {
                $weekStart = Carbon::parse($week->start_date);
                $base = $weekStart->dayOfWeekIso;
                $isReview = $week->week_type === 'review';
                $n = 1;
                foreach ($days as $d) {
                    $offset = (self::ORDER[$d] - $base + 7) % 7;
                    $date = $weekStart->copy()->addDays($offset)->toDateString();
                    // Day-edit rebuilds start today: past dates are history.
                    if ($fromDate !== null && $date < $fromDate) {
                        continue;
                    }
                    // Idempotent: never double-book a date the group owns
                    // (reactivation, retried creates, post-rebuild leftovers).
                    if (in_array($date, $taken, true)) {
                        continue;
                    }
                    $taken[] = $date;
                    $week->sessions()->create([
                        'season_id' => $season->id, 'term_id' => $term->id,
                        'group_id' => $group->id,
                        'session_number_global' => $this->nextGroupNumber($season->id, $group->id),
                        'session_number_in_week' => $n,
                        'session_type' => $isReview ? ($n < count($days) ? 'revision' : 'exam') : 'memorization',
                        'planned_date' => $date,
                        'start_time' => $defaultStart, 'end_time' => $defaultEnd,
                        'status' => 'planned',
                    ]);
                    $made++;
                    $n++;
                }
            }
            $this->refreshTermRanges($term);
        }

        return $made;
    }

    /**
     * Day-edit rebuild (owner-directed 2026-10-15): after the group's
     * weekdays change, its FUTURE planned sessions with zero recorded facts
     * are dropped and regenerated on the new days starting today. Past rows
     * stay (history never moves) and fact-carrying future rows stay (a score
     * beats a schedule). The season counter is recounted to match.
     *
     * @return array{deleted:int, created:int}
     */
    public function rebuildFutureSessions(
        AcademicSeason $season,
        Group $group,
        string $defaultStart = '21:00:00',
        string $defaultEnd = '23:00:00',
    ): array {
        return DB::transaction(function () use ($season, $group, $defaultStart, $defaultEnd) {
            $today = today()->toDateString();
            $stale = DB::table('sessions')
                ->where('season_id', $season->id)->where('group_id', $group->id)
                ->where('status', 'planned')->whereDate('planned_date', '>=', $today);
            foreach (self::SESSION_CHILDREN as $t) {
                $stale->whereNotExists(fn ($sq) => $sq->select(DB::raw(1))->from($t)
                    ->whereColumn("{$t}.session_id", 'sessions.id'));
            }
            $deleted = (clone $stale)->count();
            $stale->delete();

            $created = $this->generateForGroup($season->fresh(), $group, $defaultStart, $defaultEnd, $today);
            $season->update(['total_sessions' => DB::table('sessions')->where('season_id', $season->id)->count()]);

            return ['deleted' => $deleted, 'created' => $created];
        });
    }

    /** Active groups of the season's center (legacy NULL-center seasons: all actives). */
    private function seasonGroups(AcademicSeason $season): iterable
    {
        $q = Group::where('is_active', true)->orderBy('id');
        if ($season->center_id !== null) {
            $q->where('center_id', $season->center_id);
        }

        return $q->get();
    }

    /** The group's meeting weekdays in Mon..Sun order. */
    private function groupDays(Group $group): array
    {
        $days = $group->weekdays()->pluck('weekday')->all();
        usort($days, fn ($a, $b) => self::ORDER[$a] <=> self::ORDER[$b]);

        return $days;
    }

    private function nextGroupNumber(int $seasonId, int $groupId): int
    {
        return (int) DB::table('sessions')
            ->where('season_id', $seasonId)->where('group_id', $groupId)
            ->max('session_number_global') + 1;
    }

    private function refreshTermRanges(Term $term): void
    {
        $min = DB::table('sessions')->where('term_id', $term->id)->min('session_number_global');
        $max = DB::table('sessions')->where('term_id', $term->id)->max('session_number_global');
        $term->update(['start_session_no' => (int) $min, 'end_session_no' => (int) $max]);
    }
}
