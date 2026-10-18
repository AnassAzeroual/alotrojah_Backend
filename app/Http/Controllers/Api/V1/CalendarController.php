<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\StudentGroupScope;
use App\Http\Requests\CalendarFeedRequest;
use App\Http\Requests\UpdateSessionRequest;
use App\Http\Requests\UpdateTermRequest;
use App\Http\Requests\UpdateWeekRequest;
use App\Http\Resources\SessionCalendarResource;
use App\Http\Resources\SessionResource;
use App\Http\Resources\TermResource;
use App\Http\Resources\WeekResource;
use App\Models\AcademicSeason;
use App\Models\Group;
use App\Models\Session;
use App\Models\Term;
use App\Models\Week;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CalendarController extends Controller
{
    use StudentGroupScope;

    /**
     * Display-ready calendar window: sessions in [from, to] for one season
     * with the names their cards need — one request instead of four lookups.
     * Season visibility reuses SeasonPolicy (shared/own-center); students
     * additionally narrow to their own group.
     *
     * @response array{success: bool, message: string|null, data: array{data: list<SessionCalendarResource>, links: array{first: string|null, last: string|null, prev: string|null, next: string|null}, meta: array{current_page: int, from: int|null, last_page: int, links: list<array{url: string|null, label: string, active: bool}>, path: string, per_page: int, to: int|null, total: int}}}
     */
    public function feed(CalendarFeedRequest $request): JsonResponse
    {
        $data = $request->validated();
        $season = AcademicSeason::findOrFail((int) $data['season_id']);
        $this->authorize('view', $season);

        $q = Session::with(['group:id,name', 'term:id,name_ar', 'week:id,week_number_global,week_type'])
            ->where('season_id', $season->id)
            ->whereDate('planned_date', '>=', $data['from'])
            ->whereDate('planned_date', '<=', $data['to'])
            ->orderBy('session_number_global');
        $me = $request->user();
        if ($me->role === 'student') {
            $q->whereIn('group_id', $this->studentGroupIds($me) ?? []);
        } elseif ($me->role !== 'admin') {
            // Staff read their own center's groups (shared seasons span centers).
            $center = (int) $me->center_id;
            $q->whereHas('group', fn ($g) => $g->where('groups.center_id', $center));
        }

        return $this->ok(SessionCalendarResource::collection($q->paginate(50))->response()->getData(true));
    }
    /**
     * List a season's terms with week counts.
     */
    public function terms(Request $request, AcademicSeason $season): JsonResponse
    {
        $this->authorize('view', $season);

        return $this->ok(TermResource::collection(
            $season->terms()->withCount('weeks')->orderBy('term_number')->get()
        ));
    }

    /**
     * Show one term with its weeks and their sessions.
     *
     * Students receive only their own group's sessions. The eager-load closure
     * hides the payload from static inference, so the shape is declared.
     *
     * @response array{success: bool, message: null, data: TermResource}
     */
    public function showTerm(Request $request, Term $term): JsonResponse
    {
        $this->authorize('view', $term->season);
        $ids = $this->studentGroupIds($request->user());

        return $this->ok(new TermResource($term->load(['weeks.sessions' => function ($q) use ($ids) {
            // Students read only their own group's sessions.
            if ($ids !== null) $q->whereIn('group_id', $ids);
        }])));
    }

    /**
     * Update a term's fields. Non-admins: own center only (never shared rows).
     *
     * @response array{success: bool, message: string|null, data: TermResource}
     */
    public function updateTerm(UpdateTermRequest $request, Term $term): JsonResponse
    {
        if ($refused = $this->refuseForeignSeason($request->user(), $term->season?->center_id)) {
            return $refused;
        }
        $term->update($request->validated());

        return $this->ok(new TermResource($term->fresh()));
    }

    /**
     * List weeks, optionally narrowed (50/page).
     *
     * Filters: term_id, season_id, center_id. The center_id anchor resolves
     * the center's current season (else the newest season in its lane with
     * weeks, else empty). Students see only weeks holding their group's sessions.
     *
     * @response array{success: bool, message: string|null, data: array{data: list<WeekResource>, links: array{first: string|null, last: string|null, prev: string|null, next: string|null}, meta: array{current_page: int, from: int|null, last_page: int, links: list<array{url: string|null, label: string, active: bool}>, path: string, per_page: int, to: int|null, total: int}}}
     */
    public function weeks(Request $request): JsonResponse
    {
        $this->authorize('viewCalendar', AcademicSeason::class);
        // Term/season names ride along for picker labels (entry page); whenLoaded
        // in the resource keeps every other caller N+1-free.
        $q = Week::with(['term:id,name_ar', 'season:id,name'])->orderBy('week_number_global');
        if ($request->filled('term_id')) {
            $q->where('term_id', (int) $request->input('term_id'));
        }
        if ($request->filled('season_id')) {
            $q->where('season_id', (int) $request->input('season_id'));
        }
        if ($request->filled('center_id')) {
            // Entry page anchor: the picked group's center fixes the working
            // season — that center's own current season, else the legacy
            // shared (NULL-center) one (same lane as SeasonController@activate
            // and ExamController::currentSeasonFor). No current season: empty.
            $centerId = (int) $request->input('center_id');
            $inLane = fn ($w) => $w->where('center_id', $centerId)->orWhereNull('center_id');
            $seasonId = AcademicSeason::where('is_current', true)->where($inLane)
                ->orderByRaw('`center_id` IS NULL')->value('id');
            if ($seasonId === null) {
                // Flag lag (live case 2026-10-17: center 2 works season 1449
                // with 42 weeks but never activated it, so the lane above is
                // empty): newest season in the lane that actually holds weeks,
                // own-center first. A current-but-weekless season stays empty
                // on purpose (deliberate fresh-season empty states).
                $seasonId = AcademicSeason::where($inLane)->whereHas('weeks')
                    ->orderByRaw('`center_id` IS NULL')->orderByDesc('id')->value('id');
            }
            // No season at all in the lane: id 0 matches nothing (empty set).
            $q->where('season_id', (int) $seasonId);
        }
        if (($ids = $this->studentGroupIds($request->user())) !== null) {
            $q->whereHas('sessions', fn ($s) => $s->whereIn('group_id', $ids));
        }

        return $this->ok(WeekResource::collection($q->paginate(50))->response()->getData(true));
    }

    /**
     * Update a week's fields. Non-admins: own center only (never shared rows).
     *
     * @response array{success: bool, message: string|null, data: WeekResource}
     */
    public function updateWeek(UpdateWeekRequest $request, Week $week): JsonResponse
    {
        if ($refused = $this->refuseForeignSeason($request->user(), $week->season?->center_id)) {
            return $refused;
        }
        $week->update($request->validated());

        return $this->ok(new WeekResource($week->fresh()));
    }

    /**
     * List calendar sessions (50/page).
     *
     * Filters: season_id, term_id, week_id, session_type, status, group_id,
     * from/to date range. A non-admin filtering by another center's group is
     * refused (403 CROSS_CENTER). Students see only their own group's sessions.
     *
     * @response array{success: bool, message: string|null, data: array{data: list<SessionResource>, links: array{first: string|null, last: string|null, prev: string|null, next: string|null}, meta: array{current_page: int, from: int|null, last_page: int, links: list<array{url: string|null, label: string, active: bool}>, path: string, per_page: int, to: int|null, total: int}}}
     */
    public function sessions(Request $request): JsonResponse
    {
        $this->authorize('viewCalendar', AcademicSeason::class);
        $q = Session::orderBy('session_number_global');
        foreach (['season_id', 'term_id', 'week_id'] as $f) {
            if ($request->filled($f)) {
                $q->where($f, (int) $request->input($f));
            }
        }
        if ($request->filled('session_type')) {
            $q->where('session_type', $request->input('session_type'));
        }
        if ($request->filled('status')) {
            $q->where('status', $request->input('status'));
        }
        if ($request->filled('group_id')) {
            // Entry page anchor: sessions narrow to the picked group. A
            // non-admin asking for another center's group is refused (same
            // message/code as the weekly-exam template).
            $group = Group::findOrFail((int) $request->input('group_id'));
            $me = $request->user();
            if ($me->role !== 'admin' && (int) $group->center_id !== (int) $me->center_id) {
                return $this->fail('Group belongs to another center.', 403);
            }
            $q->where('sessions.group_id', $group->id);
        }
        if ($request->filled('from')) {
            $q->where('planned_date', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $q->where('planned_date', '<=', $request->input('to'));
        }
        if (($ids = $this->studentGroupIds($request->user())) !== null) {
            $q->whereIn('group_id', $ids);
        }

        return $this->ok(SessionResource::collection($q->paginate(50))->response()->getData(true));
    }

    /**
     * Update a session's fields (partial PATCH supported).
     *
     * End time must stay after start time (422 SESSION_TIME_ORDER). Non-admins:
     * own center only (never shared rows).
     *
     * @response array{success: bool, message: string|null, data: SessionResource}
     */
    public function updateSession(UpdateSessionRequest $request, Session $session): JsonResponse
    {
        if ($refused = $this->refuseForeignSeason($request->user(), $session->season?->center_id)) {
            return $refused;
        }
        $data = $request->validated();
        // Partial PATCHes compare against the stored row: end must be after start either way.
        $start = isset($data['start_time']) ? $data['start_time'] : substr((string) $session->start_time, 0, 5);
        $end = isset($data['end_time']) ? $data['end_time'] : substr((string) $session->end_time, 0, 5);
        if ($end <= $start) {
            return $this->fail('End time must be after start time.', 422);
        }
        $session->update($data);

        return $this->ok(new SessionResource($session->fresh()));
    }

    /** Center-owned calendar rows: non-admins touch only their own center (never shared NULL rows). */
    private function refuseForeignSeason($user, ?int $centerId): ?JsonResponse
    {
        if ($user->role !== 'admin'
            && ($centerId === null || (int) $centerId !== (int) $user->center_id)) {
            return $this->fail('Season belongs to another center.', 403);
        }

        return null;
    }
}
