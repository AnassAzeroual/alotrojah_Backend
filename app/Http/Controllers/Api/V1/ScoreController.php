<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreScoresBulkRequest;
use App\Http\Resources\AttendanceResource;
use App\Http\Resources\SessionScoreResource;
use App\Http\Resources\WeeklyGoalResource;
use App\Models\MemorizationLog;
use App\Models\RevisionLog;
use App\Models\Attendance;
use App\Models\SessionScore;
use App\Models\Student;
use App\Models\Week;
use App\Models\WeeklyGoal;
use App\Services\ScoreEntryService;
use App\Services\ScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScoreController extends Controller
{
    /** Bulk module-score entry (hifz/tajwid/mowathaba/sarraj). Max enforced from module rows. */
    public function bulk(StoreScoresBulkRequest $request, ScoreEntryService $entry): JsonResponse
    {
        $totals = $entry->storeScores($request->user(), (int) $request->input('session_id'), $request->input('records'));

        return $this->created(['weekly_totals' => $totals], 'Scores saved.');
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SessionScore::class);
        $me = $request->user();

        $q = SessionScore::with('module')->orderBy('id');
        if ($me->role === 'student') {
            $q->where('student_id', Student::where('user_id', $me->id)->value('id') ?? 0);
        } elseif ($me->role !== 'admin') {
            $q->whereHas('student', fn ($s) => $s->where('students.center_id', (int) $me->center_id));
        } elseif ($request->filled('center_id')) {
            $q->whereHas('student', fn ($s) => $s->where('students.center_id', (int) $request->input('center_id')));
        }
        foreach (['student_id', 'session_id', 'week_id', 'module_id'] as $f) {
            if ($request->filled($f)) $q->where($f, (int) $request->input($f));
        }

        return $this->ok(SessionScoreResource::collection($q->paginate(100))->response()->getData(true));
    }

    /** All scores of one session, grouped per student with totals (marking sheet). */
    public function bySession(Request $request, int $session, ScoringService $scoring): JsonResponse
    {
        $this->authorize('viewAny', SessionScore::class);
        $me = $request->user();

        $q = SessionScore::with('module')->where('session_id', $session);
        if ($me->role === 'student') {
            $q->where('student_id', Student::where('user_id', $me->id)->value('id') ?? 0);
        } elseif ($me->role !== 'admin') {
            $q->whereHas('student', fn ($s) => $s->where('students.center_id', (int) $me->center_id));
        }
        $rows = $q->get()->groupBy('student_id');
        $out = [];
        foreach ($rows as $sid => $scores) {
            $out[] = [
                'student_id' => $sid,
                'scores' => SessionScoreResource::collection($scores),
                'weekly_total' => $scoring->weeklyTotal((int) $sid, $session),
            ];
        }

        return $this->ok($out);
    }

    /**
     * Weekly follow-up page (B4): logs + module scores + revision + attendance + goal.
     * GET /v1/students/{student}/weeks/{week}/followup
     */
    public function followup(Student $student, Week $week): JsonResponse
    {
        $this->authorize('view', $student);

        $logs = MemorizationLog::where('student_id', $student->id)->where('week_id', $week->id)
            ->orderBy('session_id')->get();
        $scores = SessionScore::with('module')->where('student_id', $student->id)
            ->where('week_id', $week->id)->get()->groupBy('session_id');
        $revision = RevisionLog::where('student_id', $student->id)->where('week_id', $week->id)
            ->orderBy('session_id')->get();
        $attendance = Attendance::where('student_id', $student->id)->where('week_id', $week->id)
            ->orderBy('session_id')->get();
        $goal = WeeklyGoal::where('student_id', $student->id)->where('week_id', $week->id)->first();

        return $this->ok([
            'student' => ['id' => $student->id, 'full_name' => $student->full_name, 'memorization_mode' => $student->memorization_mode],
            'week' => ['id' => $week->id, 'week_number_global' => $week->week_number_global, 'week_type' => $week->week_type],
            'logs' => $logs,
            'scores_by_session' => $scores->map(fn ($s) => SessionScoreResource::collection($s)),
            'revision' => $revision,
            'attendance' => AttendanceResource::collection($attendance),
            'goal' => $goal ? new WeeklyGoalResource($goal) : null,
        ]);
    }
}
