<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\BulkModulesRequest;
use App\Http\Requests\StoreModuleRequest;
use App\Http\Requests\UpdateModuleRequest;
use App\Http\Resources\ScoringModuleResource;
use App\Models\ScoringModule;
use App\Models\SessionScore;
use App\Services\ScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScoringModuleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ScoringModule::class);
        $centerId = $request->filled('center_id') ? (int) $request->input('center_id') : null;

        return $this->ok(ScoringModuleResource::collection(
            ScoringModule::effectiveFor($centerId)->values()
        ));
    }

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

    /** Atomic multi-module rebalance within one set (null = shared defaults). */
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

    public function scoringCheck(Request $request, ScoringService $scoring): JsonResponse
    {
        $this->authorize('viewAny', ScoringModule::class);
        $centerId = $request->filled('center_id') ? (int) $request->input('center_id') : null;

        return $this->ok($scoring->scoringCheck($centerId));
    }

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
