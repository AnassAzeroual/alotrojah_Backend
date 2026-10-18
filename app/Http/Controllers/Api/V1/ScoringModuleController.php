<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\BulkModulesRequest;
use App\Http\Requests\ResetScoringModuleRequest;
use App\Http\Requests\StoreModuleRequest;
use App\Http\Requests\UpdateModuleRequest;
use App\Http\Resources\ScoringModuleResource;
use App\Models\ScoringModule;
use App\Models\SessionScore;
use App\Services\ScoringService;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScoringModuleController extends Controller
{
    /**
     * Effective scoring module set for a center scope (null = shared defaults).
     *
     * @response array{success: bool, message: string|null, data: list<ScoringModuleResource>}
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ScoringModule::class);
        $centerId = $request->filled('center_id') ? (int) $request->input('center_id') : null;

        return $this->ok(ScoringModuleResource::collection(
            ScoringModule::effectiveFor($centerId)->values()
        ));
    }

    /**
     * Create a scoring module (admin only).
     *
     * Rolled back with 422 SCORING_TOTAL when the center's weekly total would
     * leave 20.
     */
    #[Response(status: 201, type: 'array{success: bool, message: string|null, data: ScoringModuleResource}')]
    public function store(StoreModuleRequest $request, ScoringService $scoring): JsonResponse
    {
        return DB::transaction(function () use ($request, $scoring) {
            $module = ScoringModule::create($request->validated());
            $check = $scoring->scoringCheck($module->center_id);
            if (! $check['valid']) {
                DB::rollBack();

                return $this->fail("Weekly total would be {$check['total']}, must stay 20.", 422);
            }

            return $this->created(new ScoringModuleResource($module));
        });
    }

    /**
     * Update a scoring module (admin only), with the same weekly-total guard as store.
     *
     * @response array{success: bool, message: string|null, data: ScoringModuleResource}
     */
    public function update(UpdateModuleRequest $request, ScoringModule $scoringModule, ScoringService $scoring): JsonResponse
    {
        return DB::transaction(function () use ($request, $scoringModule, $scoring) {
            $scoringModule->update($request->validated());
            $check = $scoring->scoringCheck($scoringModule->center_id);
            if (! $check['valid']) {
                DB::rollBack();

                return $this->fail("Weekly total would be {$check['total']}, must stay 20.", 422);
            }

            return $this->ok(new ScoringModuleResource($scoringModule->fresh()));
        });
    }

    /**
     * Atomic multi-module rebalance within one set (null = shared defaults).
     *
     * @response array{success: bool, message: string|null, data: array{modules: list<ScoringModuleResource>, check: array{valid: bool, total: float}}}
     */
    public function bulk(BulkModulesRequest $request, ScoringService $scoring): JsonResponse
    {
        return DB::transaction(function () use ($request, $scoring) {
            $centerId = $request->filled('center_id') ? (int) $request->input('center_id') : null;
            if ($centerId !== null) ScoringModule::ensureOverrideSet($centerId);
            foreach ($request->input('modules') as $row) {
                ScoringModule::where('code', $row['code'])->where('center_id', $centerId)->firstOrFail()
                    ->update(array_intersect_key($row, array_flip(['max_points', 'is_active', 'is_in_weekly_total', 'sort_order'])));
            }
            $check = $scoring->scoringCheck($centerId);
            if (! $check['valid']) {
                DB::rollBack();

                return $this->fail("Weekly total would be {$check['total']}, must stay 20.", 422);
            }

            return $this->ok([
                'modules' => ScoringModuleResource::collection(
                    ScoringModule::effectiveFor($centerId)->values()
                ),
                'check' => $check,
            ], 'Scoring updated.');
        });
    }

    /**
     * Dry-run the weekly-total check for a center scope without writing.
     *
     * Returns validity plus the would-be total — used by the settings UI
     * before saving a rebalance.
     *
     * @response array{success: bool, message: string|null, data: array{valid: bool, total: float}}
     */
    public function scoringCheck(Request $request, ScoringService $scoring): JsonResponse
    {
        $this->authorize('viewAny', ScoringModule::class);
        $centerId = $request->filled('center_id') ? (int) $request->input('center_id') : null;

        return $this->ok($scoring->scoringCheck($centerId));
    }

    /**
     * Delete one center's override set, revealing the shared defaults.
     *
     * @response array{success: bool, message: string|null, data: array{deleted: int}}
     */
    public function reset(ResetScoringModuleRequest $request): JsonResponse
    {
        $centerId = (int) $request->validated()['center_id'];

        $ids = ScoringModule::where('center_id', $centerId)->pluck('id');
        if ($ids->isEmpty()) return $this->ok(['deleted' => 0]);
        if (SessionScore::whereIn('module_id', $ids)->exists()) {
            return $this->fail('Module has recorded scores and cannot be deleted.', 422);
        }
        $deleted = ScoringModule::where('center_id', $centerId)->delete();

        return $this->ok(['deleted' => $deleted]);
    }

    /**
     * Delete a single module (admin only).
     *
     * Refused with 422 MODULE_HAS_SCORES while recorded scores reference it.
     */
    public function destroy(ScoringModule $scoringModule): JsonResponse
    {
        $this->authorize('delete', $scoringModule);
        if (SessionScore::where('module_id', $scoringModule->id)->exists()) {
            return $this->fail('Module has recorded scores and cannot be deleted.', 422);
        }
        $scoringModule->delete();

        return $this->ok(null, 'Deleted.');
    }
}
