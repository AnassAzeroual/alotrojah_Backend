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
use Illuminate\Support\Facades\DB;

class SeasonController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', AcademicSeason::class);

        return $this->ok(SeasonResource::collection(
            AcademicSeason::withCount('terms')->withMin('terms as first_term_id', 'id')->orderByDesc('id')->paginate(20)
        )->response()->getData(true));
    }

    public function store(StoreSeasonRequest $request, SeasonTemplateService $tpl): JsonResponse
    {
        $season = $tpl->createSeason(
            $request->input('name'), $request->input('start_date'),
            $request->input('terms', []),
            (int) $request->input('sessions_per_week', 3),
            (int) $request->input('review_weeks_per_term', 1),
            $request->input('hijri_year'),
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
        $season->update($request->validated());

        return $this->ok(new SeasonResource($season->fresh()));
    }

    public function activate(AcademicSeason $season): JsonResponse
    {
        $this->authorize('manage', AcademicSeason::class);

        DB::transaction(function () use ($season) {
            AcademicSeason::where('is_current', true)->update(['is_current' => false]);
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
