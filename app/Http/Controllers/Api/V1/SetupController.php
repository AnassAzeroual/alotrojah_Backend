<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AcademicSeason;
use App\Models\Center;
use App\Models\Group;
use App\Models\Level;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * First-run setup checklist for the admin guide.
 *
 * The guide is ordered: center → season → levels → teacher → group → pupils.
 * It follows the first center that still has an incomplete step (so a new
 * center added later gets its own checklist); when every center is complete it
 * falls back to the first center and returns current=null.
 */
class SetupController extends Controller
{
    /**
     * Setup progress for the admin guide (admin only).
     *
     * @response array{success: bool, message: null, data: array{center: array{id: int, name: string}|null, season: array{id: int, name: string, is_current: bool}|null, total_steps: int, done_steps: int, current: string|null, steps: list<array{key: string, done: bool, count: int, target: string}>}}
     */
    public function status(Request $request): JsonResponse
    {
        abort_unless($request->user()?->role === 'admin', 403);

        $centers = Center::orderBy('id')->get(['id', 'name']);
        $center = null;
        foreach ($centers as $candidate) {
            if (! $this->complete($candidate->id)) {
                $center = $candidate;
                break;
            }
        }
        $center ??= $centers->first();

        // The guide needs the center's OWN season; the runtime shared-season
        // fallback would mark "season" done for a brand-new center that has
        // never created one.
        $season = $center === null ? null : AcademicSeason::where('center_id', $center->id)
            ->orderByDesc('is_current')->orderByDesc('id')->first();
        $teachers = $center === null ? 0 : User::where('role', 'teacher')
            ->where('center_id', $center->id)->count();
        $groups = $center === null ? 0 : Group::where('center_id', $center->id)->count();
        $pupils = $center === null ? 0 : Student::where('center_id', $center->id)->count();
        $levels = $season === null ? 0 : Level::forSeason($season->id)->count();

        $steps = [
            ['key' => 'centers', 'done' => $centers->isNotEmpty(), 'count' => $centers->count(), 'target' => '/centers'],
            ['key' => 'season', 'done' => $season !== null, 'count' => $season === null ? 0 : 1, 'target' => '/planning/new'],
            ['key' => 'levels', 'done' => $levels > 0, 'count' => $levels, 'target' => '/levels'],
            ['key' => 'teachers', 'done' => $teachers > 0, 'count' => $teachers, 'target' => '/users'],
            ['key' => 'groups', 'done' => $groups > 0, 'count' => $groups, 'target' => '/groups/new'],
            ['key' => 'pupils', 'done' => $pupils > 0, 'count' => $pupils, 'target' => '/students/new'],
        ];

        $current = null;
        foreach ($steps as $step) {
            if (! $step['done']) {
                $current = $step['key'];
                break;
            }
        }

        return $this->ok([
            'center' => $center === null ? null : ['id' => (int) $center->id, 'name' => $center->name],
            'season' => $season === null ? null : [
                'id' => (int) $season->id,
                'name' => $season->name,
                'is_current' => (bool) $season->is_current,
            ],
            'total_steps' => count($steps),
            'done_steps' => count(array_filter($steps, fn (array $step) => $step['done'])),
            'current' => $current,
            'steps' => $steps,
        ]);
    }

    /** True when a center has a season, levels, a teacher, a group and a pupil. */
    private function complete(int $centerId): bool
    {
        $season = AcademicSeason::where('center_id', $centerId)
            ->orderByDesc('is_current')->orderByDesc('id')->first();
        if ($season === null || Level::forSeason($season->id)->doesntExist()) {
            return false;
        }
        if (! User::where('role', 'teacher')->where('center_id', $centerId)->exists()) {
            return false;
        }
        if (! Group::where('center_id', $centerId)->exists()) {
            return false;
        }

        return Student::where('center_id', $centerId)->exists();
    }
}
