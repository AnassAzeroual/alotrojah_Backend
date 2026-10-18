<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreGroupRequest;
use App\Http\Requests\UpdateGroupRequest;
use App\Http\Resources\GroupResource;
use App\Http\Controllers\Concerns\StudentGroupScope;
use App\Models\AcademicSeason;
use App\Models\Group;
use App\Models\User;
use App\Services\GroupStatsService;
use App\Services\SeasonTemplateService;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GroupController extends Controller
{
    use StudentGroupScope;

    /**
     * List groups with teacher and student counts (20/page).
     *
     * Students see only their own group; teachers/supervisors see their center;
     * admins see all and may filter by center_id and is_active.
     *
     * @response array{success: bool, message: string|null, data: array{data: list<GroupResource>, links: array{first: string|null, last: string|null, prev: string|null, next: string|null}, meta: array{current_page: int, from: int|null, last_page: int, links: list<array{url: string|null, label: string, active: bool}>, path: string, per_page: int, to: int|null, total: int}}}
     */
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
        if ($request->filled('is_active')) {
            $q->where('is_active', $request->boolean('is_active'));
        }

        return $this->ok(GroupResource::collection($q->paginate(20))->response()->getData(true));
    }

    /**
     * Overview feed: every visible group + KPIs + breakdowns, current season only.
     *
     * @response array{success: bool, message: null, data: array{season: array{id: int}|null, season_name: string|null, kpis: array{groups: int, students: int, avg_score: float|null, attendance_pct: float|null, fill_pct: float|null}, breakdown: array{status: array<string, int>, gender: array<string, int>, student_type: array<string, int>, memorization_mode: array<string, int>}, groups: list<array{id: int, name: string, center_id: int, teacher: array{id: int, full_name: string, phone: string|null, teacher_type: 'hifz'|'murajaa'|'both'}|null, academic_year: string|null, schedule_days: string, is_active: bool, capacity: int|null, students_count: int, fill_pct: float|null, avg_score: float|null, attendance_pct: float|null, thumn_total: float, breakdown: array{status?: array<string, int>, gender?: array<string, int>, student_type?: array<string, int>, memorization_mode?: array<string, int>}}>}}
     */
    public function stats(Request $request, GroupStatsService $stats): JsonResponse
    {
        $this->authorize('viewAny', Group::class);
        $me = $request->user();

        $q = Group::with(['teacher'])->withCount('students')->orderBy('id');
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

    /**
     * One group: info, all its students with season metrics, weekly trend.
     *
     * @response array{success: bool, message: null, data: array{season_name: string|null, group: array{id: int, name: string, center_id: int, teacher: array{id: int, full_name: string, phone: string|null, teacher_type: 'hifz'|'murajaa'|'both'}|null, academic_year: string|null, schedule_days: string, is_active: bool, capacity: int|null, students_count: int, fill_pct: float|null, avg_score: float|null, attendance_pct: float|null, thumn_total: float, breakdown: array{status?: array<string, int>, gender?: array<string, int>, student_type?: array<string, int>, memorization_mode?: array<string, int>}}, breakdown: array{status: array<string, int>, gender: array<string, int>, student_type: array<string, int>, memorization_mode: array<string, int>}, trend: list<array{week: int, avg_score: float}>, students: list<array{id: int, full_name: string, gender: string|null, status: string, student_type: string|null, memorization_mode: 'surah'|'thumn', start_hizb: float|null, birth_date: string|null, enrollment_date: string|null, level_id: int|null, notes: string|null, avg_score: float|null, attendance_pct: float|null, thumn_total: float}>}}
     */
    public function detail(Group $group, GroupStatsService $stats): JsonResponse
    {
        $this->authorize('view', $group);
        $group->load(['teacher'])->loadCount('students');
        $season = $stats->currentSeason();

        return $this->ok($stats->detail($group, $season?->id) + ['season_name' => $season?->name]);
    }

    /**
     * Create a group (schedule days + optional teacher).
     *
     * The assigned teacher must be a teacher of the same center (422 otherwise).
     * The group is backfilled with session sets for every open season of its
     * center, so it starts with a calendar immediately.
     */
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

    /**
     * Show one group with its teacher and student count.
     */
    public function show(Group $group): JsonResponse
    {
        $this->authorize('view', $group);

        return $this->ok(new GroupResource($group->load('teacher')->loadCount('students')));
    }

    /**
     * Update a group's fields, teacher, and/or schedule days.
     *
     * Side effects: reactivating a group backfills its future sessions (same as
     * creation); changing weekdays rebuilds future planned sessions on the new
     * days — recorded history never moves.
     */
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
        // Day-edit rebuild: compare before the sync (sorted — order is display only).
        $oldDays = $group->weekdays()->pluck('weekday')->all();
        sort($oldDays);
        $newDays = $days === null ? null : $days;
        if (is_array($newDays)) sort($newDays);
        $daysChanged = $newDays !== null && $newDays !== $oldDays;
        DB::transaction(function () use ($group, $data, $days) {
            $group->update($data);
            if ($days !== null) {
                $group->weekdays()->delete();
                $group->weekdays()->createMany(array_map(fn ($d) => ['weekday' => $d], $days));
            }
        });

        // Reactivation restores the group's future: same backfill as creation.
        // (Recorded history never moves — only future fact-less rows rebuild.)
        if ($wasInactive && $group->fresh()->is_active) {
            $this->backfillGroupSessions($group->fresh(), $tpl);
        }

        // Changed weekdays rebuild the group's FUTURE: fact-less planned
        // sessions drop and regenerate on the new days (past + recorded stay).
        if ($daysChanged && $group->fresh()->is_active) {
            foreach ($this->openSeasonsFor($group->fresh()) as $season) {
                $tpl->rebuildFutureSessions($season, $group->fresh(), '21:00:00', '23:00:00');
            }
        }

        return $this->ok(new GroupResource($group->fresh('teacher')));
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
        foreach ($this->openSeasonsFor($group) as $season) {
            $tpl->generateForGroup($season, $group);
        }
    }

    /** Open seasons of the group's center (current or starting today or later). */
    private function openSeasonsFor(Group $group)
    {
        return AcademicSeason::where('center_id', $group->center_id)
            ->where(function ($q) {
                $q->where('is_current', true)
                    ->orWhereDate('start_date', '>=', today()->toDateString());
            })->get();
    }

    /**
     * Groups cannot be deleted — always 403 ADMIN_ONLY. Deactivate instead.
     */
    #[Response(status: 403, type: 'array{success: bool, message: string, errors: array{code: string}}')]
    public function destroy(Group $group): JsonResponse
    {
        $this->authorize('delete', $group);

        return $this->fail('Groups cannot be deleted.', 403);
    }
}
