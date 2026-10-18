<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\UpsertWeeklyGoalRequest;
use App\Http\Resources\WeeklyGoalResource;
use App\Models\Student;
use App\Models\Week;
use App\Models\WeeklyGoal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WeeklyGoalController extends Controller
{
    /**
     * List weekly goals (per_page 1–100, default 50).
     *
     * Non-admins see their center only. Filters: student_id, week_id.
     *
     * @response array{success: bool, message: string|null, data: array{data: list<WeeklyGoalResource>, links: array{first: string|null, last: string|null, prev: string|null, next: string|null}, meta: array{current_page: int, from: int|null, last_page: int, links: list<array{url: string|null, label: string, active: bool}>, path: string, per_page: int, to: int|null, total: int}}}
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', WeeklyGoal::class);
        $me = $request->user();

        $q = WeeklyGoal::orderBy('id');
        if ($me->role !== 'admin') {
            $q->whereHas('student', fn ($s) => $s->where('students.center_id', (int) $me->center_id));
        }
        if ($request->filled('student_id')) $q->where('student_id', (int) $request->input('student_id'));
        if ($request->filled('week_id')) $q->where('week_id', (int) $request->input('week_id'));

        // §2.20: honor per_page (clamped) like the students index.
        $perPage = min(max((int) $request->input('per_page', 50), 1), 100);

        return $this->ok(WeeklyGoalResource::collection($q->paginate($perPage))->response()->getData(true));
    }

    /**
     * Create or update one pupil's goal for one week (one row per pair).
     *
     * Records who checked the goal and when. A non-admin writing for a pupil
     * of another center gets 403 CROSS_CENTER.
     */
    public function upsert(UpsertWeeklyGoalRequest $request): JsonResponse
    {
        $data = $request->validated();
        $me = $request->user();

        $student = Student::findOrFail($data['student_id']);
        if ($me->role !== 'admin' && (int) $student->center_id !== (int) $me->center_id) {
            return $this->fail('Student is in another center.', 403);
        }
        $week = Week::findOrFail($data['week_id']);

        $goal = WeeklyGoal::updateOrCreate(
            ['student_id' => $student->id, 'week_id' => $week->id],
            ['season_id' => $week->season_id, 'target_text' => $data['target_text'] ?? null,
             'is_completed' => $data['is_completed'] ?? null,
             'checked_by' => $me->id, 'checked_at' => now()]
        );

        return $this->created(new WeeklyGoalResource($goal));
    }

    /**
     * Show one weekly goal.
     */
    public function show(WeeklyGoal $weeklyGoal): JsonResponse
    {
        $this->authorize('view', $weeklyGoal);

        return $this->ok(new WeeklyGoalResource($weeklyGoal));
    }

    /**
     * Delete a weekly goal (manage permission — admin/supervisor).
     */
    public function destroy(WeeklyGoal $weeklyGoal): JsonResponse
    {
        $this->authorize('manage', WeeklyGoal::class);
        $weeklyGoal->delete();

        return $this->ok(null, 'Deleted.');
    }
}
