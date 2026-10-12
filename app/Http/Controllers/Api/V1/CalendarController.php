<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\StudentGroupScope;
use App\Http\Requests\UpdateSessionRequest;
use App\Http\Requests\UpdateTermRequest;
use App\Http\Requests\UpdateWeekRequest;
use App\Http\Resources\SessionCalendarResource;
use App\Http\Resources\SessionResource;
use App\Http\Resources\TermResource;
use App\Http\Resources\WeekResource;
use App\Models\AcademicSeason;
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
     */
    public function feed(Request $request): JsonResponse
    {
        $this->authorize('viewCalendar', AcademicSeason::class);
        $data = $request->validate([
            'season_id' => ['required', 'integer', 'exists:academic_seasons,id'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
        ]);
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
    public function terms(Request $request, AcademicSeason $season): JsonResponse
    {
        $this->authorize('view', $season);

        return $this->ok(TermResource::collection(
            $season->terms()->withCount('weeks')->orderBy('term_number')->get()
        ));
    }

    public function showTerm(Request $request, Term $term): JsonResponse
    {
        $this->authorize('view', $term->season);
        $ids = $this->studentGroupIds($request->user());

        return $this->ok(new TermResource($term->load(['weeks.sessions' => function ($q) use ($ids) {
            // Students read only their own group's sessions.
            if ($ids !== null) $q->whereIn('group_id', $ids);
        }])));
    }

    public function updateTerm(UpdateTermRequest $request, Term $term): JsonResponse
    {
        if ($refused = $this->refuseForeignSeason($request->user(), $term->season?->center_id)) {
            return $refused;
        }
        $term->update($request->validated());

        return $this->ok(new TermResource($term->fresh()));
    }

    public function weeks(Request $request): JsonResponse
    {
        $this->authorize('viewCalendar', AcademicSeason::class);
        $q = Week::orderBy('week_number_global');
        if ($request->filled('term_id')) {
            $q->where('term_id', (int) $request->input('term_id'));
        }
        if ($request->filled('season_id')) {
            $q->where('season_id', (int) $request->input('season_id'));
        }
        if (($ids = $this->studentGroupIds($request->user())) !== null) {
            $q->whereHas('sessions', fn ($s) => $s->whereIn('group_id', $ids));
        }

        return $this->ok(WeekResource::collection($q->paginate(50))->response()->getData(true));
    }

    public function updateWeek(UpdateWeekRequest $request, Week $week): JsonResponse
    {
        if ($refused = $this->refuseForeignSeason($request->user(), $week->season?->center_id)) {
            return $refused;
        }
        $week->update($request->validated());

        return $this->ok(new WeekResource($week->fresh()));
    }

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
