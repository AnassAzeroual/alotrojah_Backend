<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreAttendanceBulkRequest;
use App\Http\Resources\AttendanceResource;
use App\Models\Attendance;
use App\Services\ScoreEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Attendance::class);
        $me = $request->user();

        $q = Attendance::with('student')->orderBy('id');
        if ($me->role === 'student') {
            $q->whereHas('student', fn ($s) => $s->where('user_id', $me->id));
        } elseif ($me->role !== 'admin') {
            $q->whereHas('student', fn ($s) => $s->where('students.center_id', (int) $me->center_id));
        } elseif ($request->filled('center_id')) {
            $q->whereHas('student', fn ($s) => $s->where('students.center_id', (int) $request->input('center_id')));
        }
        foreach (['student_id' => 'student_id', 'week_id' => 'week_id', 'session_id' => 'session_id', 'status' => 'status'] as $in => $col) {
            if ($request->filled($in)) $q->where($col, $request->input($in));
        }

        return $this->ok(AttendanceResource::collection($q->paginate(50))->response()->getData(true));
    }

    /** Bulk presence entry: one POST per session (teacher's daily flow). */
    public function bulk(StoreAttendanceBulkRequest $request, ScoreEntryService $entry): JsonResponse
    {
        $ids = $entry->storeAttendance($request->user(), (int) $request->input('session_id'), $request->input('records'));

        return $this->created(['attendance_ids' => $ids], 'Attendance saved.');
    }

    public function show(Attendance $attendance): JsonResponse
    {
        $this->authorize('view', $attendance);

        return $this->ok(new AttendanceResource($attendance->load('student')));
    }

    public function destroy(Attendance $attendance): JsonResponse
    {
        $this->authorize('manage', Attendance::class);
        $attendance->delete();

        return $this->ok(null, 'Deleted.');
    }
}
