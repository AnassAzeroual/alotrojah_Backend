<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\UpdateSessionRequest;
use App\Http\Requests\UpdateTermRequest;
use App\Http\Requests\UpdateWeekRequest;
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
    public function terms(Request $request, AcademicSeason $season): JsonResponse
    {
        $this->authorize('view', $season);

        return $this->ok(TermResource::collection(
            $season->terms()->withCount('weeks')->orderBy('term_number')->get()
        ));
    }

    public function showTerm(Term $term): JsonResponse
    {
        $this->authorize('view', $term->season);

        return $this->ok(new TermResource($term->load(['weeks.sessions'])));
    }

    public function updateTerm(UpdateTermRequest $request, Term $term): JsonResponse
    {
        if ($refused = $this->refuseForeignSeason($request->user(), $term->season?->center_id)) return $refused;
        $term->update($request->validated());

        return $this->ok(new TermResource($term->fresh()));
    }

    public function weeks(Request $request): JsonResponse
    {
        $this->authorize('viewCalendar', AcademicSeason::class);
        $q = Week::orderBy('week_number_global');
        if ($request->filled('term_id')) $q->where('term_id', (int) $request->input('term_id'));
        if ($request->filled('season_id')) $q->where('season_id', (int) $request->input('season_id'));

        return $this->ok(WeekResource::collection($q->paginate(50))->response()->getData(true));
    }

    public function updateWeek(UpdateWeekRequest $request, Week $week): JsonResponse
    {
        if ($refused = $this->refuseForeignSeason($request->user(), $week->season?->center_id)) return $refused;
        $week->update($request->validated());

        return $this->ok(new WeekResource($week->fresh()));
    }

    public function sessions(Request $request): JsonResponse
    {
        $this->authorize('viewCalendar', AcademicSeason::class);
        $q = Session::orderBy('session_number_global');
        foreach (['season_id', 'term_id', 'week_id'] as $f) {
            if ($request->filled($f)) $q->where($f, (int) $request->input($f));
        }
        if ($request->filled('session_type')) $q->where('session_type', $request->input('session_type'));
        if ($request->filled('status')) $q->where('status', $request->input('status'));
        if ($request->filled('from')) $q->where('planned_date', '>=', $request->input('from'));
        if ($request->filled('to')) $q->where('planned_date', '<=', $request->input('to'));

        return $this->ok(SessionResource::collection($q->paginate(50))->response()->getData(true));
    }

    public function updateSession(UpdateSessionRequest $request, Session $session): JsonResponse
    {
        if ($refused = $this->refuseForeignSeason($request->user(), $session->season?->center_id)) return $refused;
        $session->update($request->validated());

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
