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
use Illuminate\Support\Facades\DB;

class ScoringModuleController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', ScoringModule::class);

        return $this->ok(ScoringModuleResource::collection(
            ScoringModule::orderBy('sort_order')->get()
        ));
    }

    public function store(StoreModuleRequest $request, ScoringService $scoring): JsonResponse
    {
        return DB::transaction(function () use ($request, $scoring) {
            $module = ScoringModule::create($request->validated());
            $check = $scoring->scoringCheck();
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
            $check = $scoring->scoringCheck();
            if (! $check['valid']) {
                DB::rollBack();

                return $this->fail("Weekly total would be {$check['total']}, must stay 20.", 422);
            }

            return $this->ok(new ScoringModuleResource($scoringModule->fresh()));
        });
    }

    /** Atomic multi-module rebalance (the real manager UX). */
    public function bulk(BulkModulesRequest $request, ScoringService $scoring): JsonResponse
    {
        return DB::transaction(function () use ($request, $scoring) {
            foreach ($request->input('modules') as $row) {
                ScoringModule::where('code', $row['code'])->firstOrFail()
                    ->update(array_intersect_key($row, array_flip(['max_points', 'is_active', 'is_in_weekly_total', 'sort_order'])));
            }
            $check = $scoring->scoringCheck();
            if (! $check['valid']) {
                DB::rollBack();

                return $this->fail("Weekly total would be {$check['total']}, must stay 20.", 422);
            }

            return $this->ok([
                'modules' => ScoringModuleResource::collection(ScoringModule::orderBy('sort_order')->get()),
                'check' => $check,
            ], 'Scoring updated.');
        });
    }

    public function scoringCheck(ScoringService $scoring): JsonResponse
    {
        $this->authorize('viewAny', ScoringModule::class);

        return $this->ok($scoring->scoringCheck());
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
