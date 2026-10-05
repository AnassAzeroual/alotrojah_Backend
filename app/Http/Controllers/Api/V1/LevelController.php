<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreLevelRequest;
use App\Http\Requests\UpdateLevelRequest;
use App\Models\Group;
use App\Models\Level;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LevelController extends Controller
{
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

    public function destroy(Level $level): JsonResponse
    {
        $this->authorize('delete', $level);
        if (Group::where('level_id', $level->id)->exists()
            || Student::where('level_id', $level->id)->exists()) {
            return $this->fail('Level is used by groups or pupils and cannot be deleted.', 422);
        }
        $level->delete();

        return $this->ok(null, 'Deleted.');
    }
}
