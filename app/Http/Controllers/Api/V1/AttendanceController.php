<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreAttendanceBulkRequest;
use App\Http\Resources\AttendanceResource;
use App\Models\Attendance;
use App\Services\ScoreEntryService;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    /**
     * List attendance rows with their student (50/page).
     *
     * Role-narrowed like every operational list (own / own center / all for
     * admins). Filters: student_id, week_id, session_id, status.
     *
     * @response array{success: bool, message: string|null, data: array{data: list<AttendanceResource>, links: array{first: string|null, last: string|null, prev: string|null, next: string|null}, meta: array{current_page: int, from: int|null, last_page: int, links: list<array{url: string|null, label: string, active: bool}>, path: string, per_page: int, to: int|null, total: int}}}
     */
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
    #[Response(status: 201, type: 'array{success: bool, message: string, data: array{attendance_ids: list<int>}}')]
    public function bulk(StoreAttendanceBulkRequest $request, ScoreEntryService $entry): JsonResponse
    {
        $ids = $entry->storeAttendance($request->user(), (int) $request->input('session_id'), $request->input('records'));

        return $this->created(['attendance_ids' => $ids], 'Attendance saved.');
    }

    /**
     * Show one attendance row with its student.
     */
    public function show(Attendance $attendance): JsonResponse
    {
        $this->authorize('view', $attendance);

        return $this->ok(new AttendanceResource($attendance->load('student')));
    }

    /**
     * Delete an attendance row (manage permission — admin/supervisor).
     */
    public function destroy(Attendance $attendance): JsonResponse
    {
        $this->authorize('manage', Attendance::class);
        $attendance->delete();

        return $this->ok(null, 'Deleted.');
    }
}
