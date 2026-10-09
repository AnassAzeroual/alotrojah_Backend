<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreGroupRequest;
use App\Http\Requests\UpdateGroupRequest;
use App\Http\Resources\GroupResource;
use App\Models\AcademicSeason;
use App\Models\Group;
use App\Models\Student;
use App\Models\User;
use App\Services\GroupStatsService;
use App\Services\SeasonTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GroupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Group::class);
        $me = $request->user();

        $q = Group::with('teacher')->withCount('students')->orderBy('id');
        if ($me->role === 'student') {
            // Own group only (pupil link; groupless pupils see nothing).
            $q->whereIn('groups.id', $this->studentGroupIds($me));
        } elseif ($me->role !== 'admin') {
            $q->forCenter((int) $me->center_id);
        } elseif ($request->filled('center_id')) {
            $q->where('center_id', (int) $request->input('center_id'));
        }
        if ($request->filled('level_id')) {
            $q->where('level_id', (int) $request->input('level_id'));
        }
        if ($request->filled('is_active')) {
            $q->where('is_active', $request->boolean('is_active'));
        }

        return $this->ok(GroupResource::collection($q->paginate(20))->response()->getData(true));
    }

    /** Overview feed: every visible group + KPIs + breakdowns, current season only. */
    public function stats(Request $request, GroupStatsService $stats): JsonResponse
    {
        $this->authorize('viewAny', Group::class);
        $me = $request->user();

        $q = Group::with(['teacher', 'level'])->withCount('students')->orderBy('id');
        if ($me->role === 'student') {
            $q->whereIn('groups.id', $this->studentGroupIds($me));
        } elseif ($me->role !== 'admin') {
            $q->forCenter((int) $me->center_id);
        } elseif ($request->filled('center_id')) {
            $q->where('center_id', (int) $request->input('center_id'));
        }

        $season = $stats->currentSeason();

        return $this->ok($stats->overview($q->get(), $season?->id) + ['season_name' => $season?->name]);
    }

    /** One group: info, all its students with season metrics, weekly trend. */
    public function detail(Group $group, GroupStatsService $stats): JsonResponse
    {
        $this->authorize('view', $group);
        $group->load(['teacher', 'level'])->loadCount('students');
        $season = $stats->currentSeason();

        return $this->ok($stats->detail($group, $season?->id) + ['season_name' => $season?->name]);
    }

    public function store(StoreGroupRequest $request, SeasonTemplateService $tpl): JsonResponse
    {
        $data = $request->validated();
        $me = $request->user();
        if ($me->role !== 'admin') {
            $data['center_id'] = $me->center_id;
        }

        if (! empty($data['teacher_id'])) {
            $t = User::findOrFail($data['teacher_id']);
            if ($t->role !== 'teacher' || (int) $t->center_id !== (int) $data['center_id']) {
                return $this->fail('Teacher must belong to the same center.', 422);
            }
        }

        $days = array_values(array_unique($data['schedule_days'] ?? []));
        unset($data['schedule_days']);

        $group = DB::transaction(function () use ($data, $days) {
            $g = Group::create($data);
            $g->weekdays()->createMany(array_map(fn ($d) => ['weekday' => $d], $days));

            return $g;
        });
        // A group born after its seasons would otherwise own zero sessions:
        // backfill its sets for every open season of its center.
        $this->backfillGroupSessions($group->fresh(), $tpl);

        return $this->created(new GroupResource($group->load('teacher')));
    }

    public function show(Group $group): JsonResponse
    {
        $this->authorize('view', $group);

        return $this->ok(new GroupResource($group->load('teacher')->loadCount('students')));
    }

    public function update(UpdateGroupRequest $request, Group $group, SeasonTemplateService $tpl): JsonResponse
    {
        $data = $request->validated();

        if (! empty($data['teacher_id'])) {
            $t = User::findOrFail($data['teacher_id']);
            if ($t->role !== 'teacher' || (int) $t->center_id !== (int) $group->center_id) {
                return $this->fail('Teacher must belong to the same center.', 422);
            }
        }

        $days = array_key_exists('schedule_days', $data) ? array_values(array_unique($data['schedule_days'] ?? [])) : null;
        unset($data['schedule_days']);

        $wasInactive = ! $group->is_active;
        DB::transaction(function () use ($group, $data, $days) {
            $group->update($data);
            if ($days !== null) {
                $group->weekdays()->delete();
                $group->weekdays()->createMany(array_map(fn ($d) => ['weekday' => $d], $days));
            }
        });

        // Reactivation restores the group's future: same backfill as creation.
        // (Day-list edits apply to future generations only — existing session
        // rows keep their dates so recorded history never moves.)
        if ($wasInactive && $group->fresh()->is_active) {
            $this->backfillGroupSessions($group->fresh(), $tpl);
        }

        return $this->ok(new GroupResource($group->fresh('teacher')));
    }

    /**
     * Own-group ids for a student user (empty when the pupil link has no
     * group — whereIn([]) then matches nothing, by design).
     *
     * @return int[]
     */
    private function studentGroupIds(User $user): array
    {
        return Student::where('user_id', $user->id)->whereNotNull('group_id')
            ->pluck('group_id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Give a new/reactivated group its session sets for every open season of
     * its center (current or starting today or later — closed history stays
     * closed). Inactive groups own no sessions by design.
     */
    private function backfillGroupSessions(Group $group, SeasonTemplateService $tpl): void
    {
        if (! $group->is_active) {
            return;
        }
        $seasons = AcademicSeason::where('center_id', $group->center_id)
            ->where(function ($q) {
                $q->where('is_current', true)
                    ->orWhereDate('start_date', '>=', today()->toDateString());
            })->get();
        foreach ($seasons as $season) {
            $tpl->generateForGroup($season, $group);
        }
    }

    public function destroy(Group $group): JsonResponse
    {
        $this->authorize('delete', $group);

        return $this->fail('Groups cannot be deleted.', 403);
    }
}
