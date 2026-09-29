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

        return $this->ok(WeeklyGoalResource::collection($q->paginate(50))->response()->getData(true));
    }

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

    public function show(WeeklyGoal $weeklyGoal): JsonResponse
    {
        $this->authorize('view', $weeklyGoal);

        return $this->ok(new WeeklyGoalResource($weeklyGoal));
    }

    public function destroy(WeeklyGoal $weeklyGoal): JsonResponse
    {
        $this->authorize('manage', WeeklyGoal::class);
        $weeklyGoal->delete();

        return $this->ok(null, 'Deleted.');
    }
}
