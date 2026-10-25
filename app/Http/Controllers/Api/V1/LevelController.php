<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\ResetLevelRequest;
use App\Http\Requests\StoreLevelRequest;
use App\Http\Requests\UpdateLevelRequest;
use App\Http\Resources\LevelResource;
use App\Models\AcademicSeason;
use App\Models\Level;
use App\Models\Student;
use App\Models\StudentLevel;
use App\Models\Term;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Levels belong to a season (and a season carries its center). A season owns
 * one admin-created set; a new season copies the previous season of the same
 * center lane. There is no static seed and no NULL-season live set.
 */
class LevelController extends Controller
{
    /**
     * Level set for a scope.
     *
     * `?season_id=` that season; `?center_id=` that center's current (else
     * latest) season, with the legacy shared season as fallback. No scope (or
     * a center with no season) returns an empty set — levels are never global.
     *
     * @response array{success: bool, message: string|null, data: list<LevelResource>}
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Level::class);
        [$levels, $season] = $this->resolveScope($request);

        return $this->ok($this->present($levels, $season));
    }

    /**
     * Create a level inside one season; the code is the server's `L{id}`.
     *
     * @response array{success: bool, message: string|null, data: LevelResource}
     */
    public function store(StoreLevelRequest $request): JsonResponse
    {
        $data = $request->validated();
        $season = AcademicSeason::findOrFail((int) $data['season_id']);
        unset($data['season_id']);

        $twin = Level::forSeason($season->id)
            ->where('sessions_per_week', $data['sessions_per_week'])
            ->where('thumn_per_session_value', $data['thumn_per_session_value'])
            ->where('thumn_per_week_value', $data['thumn_per_week_value'])
            ->where('ahzab_per_term', $data['ahzab_per_term'])
            ->where('ahzab_per_dawra', $data['ahzab_per_dawra'])
            ->where('total_ahzab', $data['total_ahzab'])
            ->exists();
        if ($twin) {
            return $this->fail('A level with identical values already exists.', 422, ['code' => 'LEVEL_EXISTS']);
        }

        $level = DB::transaction(function () use ($data, $season) {
            // Serialize creators through the InnoDB gap lock: auto-increment
            // can sit far above max(id) (rollbacks/deletes), so the final
            // code must come from the real id, not from a max(id) prediction.
            // The row is born with the schema placeholder default, stamped
            // below before the transaction commits — never visible as L0.
            Level::query()->lockForUpdate()->max('id');
            $level = Level::create($data + [
                'season_id' => $season->id,
                'center_id' => $season->center_id,
            ]);
            $code = 'L'.$level->id;
            $level->update(['code' => $code]);

            return $level->fresh();
        });

        return $this->created($this->presentOne($level, $season));
    }

    /**
     * Edit one level row in place (admin only).
     *
     * @response array{success: bool, message: string|null, data: LevelResource}
     */
    public function update(UpdateLevelRequest $request, Level $level): JsonResponse
    {
        $level->update($request->validated());
        $fresh = $level->fresh();
        $season = $fresh->season_id === null ? null : AcademicSeason::find($fresh->season_id);

        return $this->ok($this->presentOne($fresh, $season));
    }

    /**
     * Restore a season's set from the previous season of its center lane:
     * delete the season's rows and copy again (or copy into an empty season).
     * Refused while pupils are placed on any of those rows.
     *
     * @response array{success: bool, message: string|null, data: array{deleted: int, created: int}}
     */
    public function reset(ResetLevelRequest $request): JsonResponse
    {
        $season = AcademicSeason::findOrFail((int) $request->validated()['season_id']);
        $ids = Level::forSeason($season->id)->pluck('id');
        // Recorded placements always win over "no source": the set cannot be
        // replaced at all while a pupil sits on it.
        if ($ids->isNotEmpty()
            && (Student::whereIn('level_id', $ids)->exists()
                || StudentLevel::whereIn('level_id', $ids)->exists())) {
            return $this->fail('Level is used by pupils and cannot be deleted.', 422);
        }
        if (Level::previousSeasonFor($season) === null) {
            return $this->fail('No previous season to copy levels from.', 422);
        }
        if ($ids->isEmpty()) {
            return $this->ok(['deleted' => 0, 'created' => Level::cloneIntoSeason($season)]);
        }

        return DB::transaction(function () use ($season, $ids) {
            Level::whereIn('id', $ids)->delete();

            return $this->ok([
                'deleted' => $ids->count(),
                'created' => Level::cloneIntoSeason($season),
            ]);
        });
    }

    /**
     * Delete a single level (admin only).
     *
     * Refused with 422 LEVEL_IN_USE while any pupil is assigned to it.
     */
    public function destroy(Level $level): JsonResponse
    {
        $this->authorize('delete', $level);
        if (Student::where('level_id', $level->id)->exists()
            || StudentLevel::where('level_id', $level->id)->exists()) {
            return $this->fail('Level is used by pupils and cannot be deleted.', 422);
        }
        $level->delete();

        return $this->ok(null, 'Deleted.');
    }

    /** @return array{0: Collection<int, Level>, 1: AcademicSeason|null} */
    private function resolveScope(Request $request): array
    {
        if ($request->filled('season_id')) {
            $season = AcademicSeason::findOrFail((int) $request->input('season_id'));
            $this->authorize('view', $season);

            return [Level::forSeason($season->id)->orderBy('id')->get(), $season];
        }

        if ($request->filled('center_id')) {
            $season = AcademicSeason::currentFor((int) $request->input('center_id'));

            return [
                $season !== null ? Level::forSeason($season->id)->orderBy('id')->get() : collect(),
                $season,
            ];
        }

        // No scope: levels are never global any more.
        return [collect(), null];
    }

    private function presentOne(Level $level, ?AcademicSeason $season): array
    {
        return (new LevelResource($level, $level->termGoals($this->seasonTerms($season))))->toArray();
    }

    /** @return list<array<string, mixed>> */
    private function present(Collection $levels, ?AcademicSeason $season): array
    {
        $terms = $this->seasonTerms($season);

        return $levels->map(fn (Level $level) => (new LevelResource($level, $level->termGoals($terms)))->toArray())->all();
    }

    /** @return Collection<int, Term> */
    private function seasonTerms(?AcademicSeason $season): Collection
    {
        return $season !== null
            ? $season->terms()->with('weeks')->orderBy('term_number')->get()
            : collect();
    }
}
