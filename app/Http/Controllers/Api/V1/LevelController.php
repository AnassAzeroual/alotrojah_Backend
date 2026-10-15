<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\ResetLevelRequest;
use App\Http\Requests\StoreLevelRequest;
use App\Http\Requests\UpdateLevelRequest;
use App\Models\Level;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LevelController extends Controller
{
    /**
     * Create a level with a dynamic `L{id}` code. A NULL center_id creates a
     * shared default visible to every center; a center_id scopes it (admin
     * only either way, via StoreLevelRequest).
     */
    public function store(StoreLevelRequest $request): JsonResponse
    {
        $data = $request->validated();
        $scope = $data['center_id'] ?? null;
        $twin = Level::where('sessions_per_week', $data['sessions_per_week'])
            ->where('thumn_per_session_value', $data['thumn_per_session_value'])
            ->where('thumn_per_week_value', $data['thumn_per_week_value'])
            ->where('ahzab_per_term', $data['ahzab_per_term'])
            ->where('ahzab_per_dawra', $data['ahzab_per_dawra'])
            ->where('total_ahzab', $data['total_ahzab'])
            ->when($scope === null, fn ($q) => $q->whereNull('center_id'), fn ($q) => $q->where('center_id', $scope))
            ->exists();
        if ($twin) {
            return $this->fail('A level with identical values already exists.', 422, ['code' => 'LEVEL_EXISTS']);
        }
        $level = DB::transaction(function () use ($data) {
            // Serialize creators through the InnoDB gap lock: auto-increment
            // can sit far above max(id) (rollbacks/deletes), so the final
            // code must come from the real id, not from a max(id) prediction.
            // The row is born with the schema placeholder default, stamped
            // below before the transaction commits — never visible as L0.
            Level::query()->lockForUpdate()->max('id');
            $level = Level::create($data);
            $code = 'L'.$level->id;
            $level->update(['code' => $code]);

            return $level->fresh();
        });

        return $this->created($level->fresh());
    }

    /** Effective set for a center scope (null = shared defaults). */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Level::class);
        $centerId = $request->filled('center_id') ? (int) $request->input('center_id') : null;

        return $this->ok(Level::effectiveFor($centerId)->values());
    }

    /**
     * Single-row edit. With a `center_id` scope on a shared default row, the
     * center's override set is cloned first and the clone is edited instead —
     * defaults are never mutated through a scoped edit. (No store endpoint:
     * `levels.code` is a fixed L1/L2/L3 ENUM, so rows are edited, never added.)
     */
    public function update(UpdateLevelRequest $request, Level $level): JsonResponse
    {
        $data = $request->validated();
        $scope = $data['center_id'] ?? null;
        unset($data['center_id']);
        if ($scope !== null && $level->center_id === null) {
            Level::ensureOverrideSet($scope);
            $level = Level::where('code', $level->code)->where('center_id', $scope)->firstOrFail();
        }
        $level->update($data);

        return $this->ok($level->fresh());
    }

    /** Delete one center's override set, revealing the shared defaults. */
    public function reset(ResetLevelRequest $request): JsonResponse
    {
        $centerId = (int) $request->validated()['center_id'];

        $ids = Level::where('center_id', $centerId)->pluck('id');
        if ($ids->isEmpty()) return $this->ok(['deleted' => 0]);
        if (Student::whereIn('level_id', $ids)->exists()) {
            return $this->fail('Level is used by pupils and cannot be deleted.', 422);
        }
        $deleted = Level::where('center_id', $centerId)->delete();

        return $this->ok(['deleted' => $deleted]);
    }

    public function destroy(Level $level): JsonResponse
    {
        $this->authorize('delete', $level);
        if (Student::where('level_id', $level->id)->exists()) {
            return $this->fail('Level is used by pupils and cannot be deleted.', 422);
        }
        $level->delete();

        return $this->ok(null, 'Deleted.');
    }
}
