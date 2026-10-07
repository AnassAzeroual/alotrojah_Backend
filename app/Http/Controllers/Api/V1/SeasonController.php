<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreSeasonRequest;
use App\Http\Requests\UpdateSeasonRequest;
use App\Http\Resources\SeasonResource;
use App\Models\AcademicSeason;
use App\Models\Attendance;
use App\Models\Exam;
use App\Models\MemorizationLog;
use App\Models\SessionScore;
use App\Services\SeasonTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SeasonController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AcademicSeason::class);
        $me = $request->user();

        $q = AcademicSeason::withCount('terms')->withMin('terms as first_term_id', 'id')->orderByDesc('id');
        if ($me->role !== 'admin') {
            // Staff see legacy shared seasons plus their own center's.
            $q->where(fn ($w) => $w->whereNull('center_id')->orWhere('center_id', (int) $me->center_id));
        }

        return $this->ok(SeasonResource::collection($q->paginate(20))->response()->getData(true));
    }

    public function store(StoreSeasonRequest $request, SeasonTemplateService $tpl): JsonResponse
    {
        $season = $tpl->createSeason(
            $request->input('name'), $request->input('start_date'),
            $request->input('terms', []),
            (int) $request->input('sessions_per_week', 3),
            (int) $request->input('review_weeks_per_term', 1),
            $request->input('hijri_year'),
            $request->input('center_id'),
        );

        return $this->created(new SeasonResource($season->loadCount('terms')->loadMin('terms as first_term_id', 'id')));
    }

    public function show(AcademicSeason $season): JsonResponse
    {
        $this->authorize('view', $season);

        return $this->ok(new SeasonResource($season->loadCount('terms')->loadMin('terms as first_term_id', 'id')));
    }

    public function update(UpdateSeasonRequest $request, AcademicSeason $season): JsonResponse
    {
        // Seasons are center-owned: a non-admin touches only their own
        // center's seasons (legacy shared rows are admin-only for writes).
        $me = $request->user();
        if ($me->role !== 'admin'
            && ($season->center_id === null || (int) $season->center_id !== (int) $me->center_id)) {
            return $this->fail('Season belongs to another center.', 403);
        }
        $season->update($request->validated());

        return $this->ok(new SeasonResource($season->fresh()));
    }

    public function activate(AcademicSeason $season): JsonResponse
    {
        $this->authorize('manage', AcademicSeason::class);
        $me = request()->user();
        if ($me->role !== 'admin'
            && ($season->center_id === null || (int) $season->center_id !== (int) $me->center_id)) {
            return $this->fail('Season belongs to another center.', 403);
        }

        // Current-season is exclusive per center lane (NULL lane included).
        $lane = AcademicSeason::where('is_current', true);
        if ($season->center_id === null) $lane->whereNull('center_id');
        else $lane->where('center_id', $season->center_id);
        DB::transaction(function () use ($lane, $season) {
            $lane->update(['is_current' => false]);
            $season->update(['is_current' => true]);
        });

        return $this->ok(new SeasonResource($season->fresh()));
    }

    public function destroy(AcademicSeason $season): JsonResponse
    {
        $this->authorize('delete', $season);

        $hasFacts = MemorizationLog::where('season_id', $season->id)->exists()
            || SessionScore::where('season_id', $season->id)->exists()
            || Attendance::where('season_id', $season->id)->exists()
            || Exam::where('season_id', $season->id)->exists();
        if ($hasFacts) return $this->fail('Season has recorded facts and cannot be deleted.', 422);

        $season->delete();

        return $this->ok(null, 'Deleted.');
    }
}
