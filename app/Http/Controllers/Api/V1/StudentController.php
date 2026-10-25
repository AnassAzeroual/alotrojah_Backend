<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreStudentRequest;
use App\Http\Requests\UpdateStudentRequest;
use App\Http\Resources\ExamResource;
use App\Http\Resources\GroupResource;
use App\Http\Resources\StudentResource;
use App\Models\AcademicSeason;
use App\Models\Center;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\Group;
use App\Models\Level;
use App\Models\MemorizationLog;
use App\Models\Student;
use App\Models\StudentLevel;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class StudentController extends Controller
{
    /**
     * List pupils with their group (paginated; per_page 1–100, default 20).
     *
     * Students see only themselves; teachers/supervisors their center; admins
     * all (optional center_id). Filters: group_id, level_id, status,
     * memorization_mode, q (full_name substring), unassigned (no group).
     *
     * @response array{success: bool, message: string|null, data: array{data: list<StudentResource>, links: array{first: string|null, last: string|null, prev: string|null, next: string|null}, meta: array{current_page: int, from: int|null, last_page: int, links: list<array{url: string|null, label: string, active: bool}>, path: string, per_page: int, to: int|null, total: int}}}
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Student::class);
        $me = $request->user();

        $q = Student::with(['group', 'level'])->orderBy('id');

        if ($me->role === 'student') {
            $q->where('user_id', $me->id);
        } elseif ($me->role !== 'admin') {
            $q->forCenter((int) $me->center_id);
        } elseif ($request->filled('center_id')) {
            $q->where('center_id', (int) $request->input('center_id'));
        }
        // Levels are per season, so `students.level_id` points at this season's
        // clone while the filter options come from the (season-independent)
        // code set. Filter by code, never by the raw season row id.
        if ($request->filled('level_id')) {
            $code = Level::whereKey((int) $request->input('level_id'))->value('code');
            if ($code === null) {
                $q->whereRaw('1 = 0');
            } else {
                $q->whereHas('level', fn ($l) => $l->where('levels.code', $code));
            }
        }
        foreach (['group_id', 'status', 'memorization_mode'] as $f) {
            if ($request->filled($f)) {
                $q->where($f, $request->input($f));
            }
        }
        if ($request->filled('q')) {
            $q->where('full_name', 'like', '%'.$request->input('q').'%');
        }
        // Pupils with no group assigned.
        if ($request->boolean('unassigned')) {
            $q->whereNull('group_id');
        }

        // §2.2: honor per_page (clamped) instead of silently truncating at 20.
        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);

        return $this->ok(StudentResource::collection($q->paginate($perPage))->response()->getData(true));
    }

    /**
     * Create a pupil row and either link an existing login account or create a
     * new student account in the same transaction.
     *
     * Payload carries exactly one of:
     *  - user_id: an existing unlinked student account, or
     *  - account: {email, password} — the user row is created from the pupil's
     *    name/center and linked; a failure rolls the whole enrollment back.
     *
     * The group and a linked account must belong to the pupil's center
     * (422 CROSS_CENTER otherwise). On a single-center deployment an admin may
     * omit center_id and gets the sole center.
     */
    public function store(StoreStudentRequest $request): JsonResponse
    {
        $data = $request->validated();
        $newAccount = ! empty($data['account']) ? $data['account'] : null;
        unset($data['account']);

        $me = $request->user();
        if ($me->role !== 'admin') {
            $data['center_id'] = $me->center_id;
        }
        // §2.1: an admin who omits the center on a single-center deployment
        // gets the sole center instead of a NULL-center (orphan) pupil.
        if ($me->role === 'admin' && empty($data['center_id']) && Center::count() === 1) {
            $data['center_id'] = Center::value('id');
        }

        if (! empty($data['group_id'])) {
            $group = Group::findOrFail($data['group_id']);
            // NULL-center pupils may sit in a group until the admin repairs
            // the center (§2.1); the cross-center check only fires when both
            // sides are set.
            if (! empty($data['center_id']) && (int) $group->center_id !== (int) $data['center_id']) {
                return $this->fail('Group belongs to another center.', 422);
            }
        }

        if ($newAccount !== null) {
            // A new student user is operational data: it needs a center. The
            // form requires one; this is the server-side backstop.
            if (empty($data['center_id'])) {
                return $this->fail('Choose a center for the pupil account.', 422);
            }
        } else {
            // The linked account lives in exactly one center — it must match
            // the pupil's, otherwise policies/lists read the two sides apart.
            $linked = User::findOrFail($data['user_id']);
            if (! empty($data['center_id']) && (int) $linked->center_id !== (int) $data['center_id']) {
                return $this->fail('Pupil account belongs to another center.', 422);
            }
        }

        return $this->created(new StudentResource(DB::transaction(function () use ($data, $newAccount) {
            if ($newAccount !== null) {
                $user = User::create([
                    'full_name' => $data['full_name'],
                    'email' => $newAccount['email'],
                    'password_hash' => Hash::make($newAccount['password']),
                    'role' => 'student',
                    'center_id' => $data['center_id'],
                    'is_active' => true,
                ]);
                $data['user_id'] = $user->id;
            }

            $student = Student::create($data);
            // Placement is per season now: write the current season's row and
            // keep the compatibility pointer on the pupil row in step.
            $placed = StudentLevel::place($student, (int) $data['level_id']);
            if ($placed !== null && $placed !== (int) $student->level_id) {
                $student->update(['level_id' => $placed]);
            }

            return $student->load('user');
        })));
    }

    /**
     * Accounts that can still be linked: active student-role users with no
     * pupil row. Same writers as pupil creation (admin/supervisor/teacher),
     * center-narrowed like everything operational.
     */
    public function unlinkedAccounts(Request $request): JsonResponse
    {
        $this->authorize('create', Student::class);
        $me = $request->user();

        $q = User::where('role', 'student')->where('is_active', true)
            ->whereNotExists(function ($sq) {
                $sq->select(DB::raw(1))->from('students')
                    ->whereColumn('students.user_id', 'users.id');
            })->orderBy('full_name');
        if ($me->role !== 'admin') {
            $q->where('center_id', (int) $me->center_id);
        } elseif ($request->filled('center_id')) {
            $q->where('center_id', (int) $request->input('center_id'));
        }

        return $this->ok($q->get(['id', 'full_name', 'email', 'center_id']));
    }

    /**
     * Student-first exam builder brief: profile, season stats, memorization
     * history, recent exams and a suggested current portion.
     *
     * The portion mode is the pupil's `memorization_mode` (thumn/surah); the
     * suggestion comes from the newest previous exam's matching question, then
     * from the latest memorization log's stored portion. The examiner may edit
     * it before creating the exam.
     *
     * @response array{success: bool, message: string|null, data: array{student: StudentResource, group: GroupResource|null, season: array{id: int, name: string}|null, summary: array{season_thumn: float|null, season_avg_score: float|null, season_avg_sarraj: float|null, season_attendance_pct: float|null, season_overall_avg: float|null, honor_flag: string|null}|null, memorization: array{last: array{id: int, log_date: string, log_mode: 'thumn'|'surah', thumn_amount: float, hizb_no: float|null, thumn_no: int|null, surah_ref: int|null, ayah_from: int|null, ayah_to: int|null}|null, items: list<array{id: int, log_date: string, log_mode: 'thumn'|'surah', thumn_amount: float, hizb_no: float|null, thumn_no: int|null, surah_ref: int|null, ayah_from: int|null, ayah_to: int|null}>, total_thumn: float}, exams: list<ExamResource>, current: array{mode: 'thumn'|'surah', hizb: float|null, thumn: int|null, surah: int|null, ayah_from: int|null, ayah_to: int|null, source: 'last_exam'|'last_log'|null}}}
     */
    public function examBrief(Student $student, DashboardService $dash): JsonResponse
    {
        $this->authorize('view', $student);
        $student->load(['group.teacher', 'level', 'user']);

        $season = $student->center_id === null
            ? null
            : AcademicSeason::currentFor((int) $student->center_id);
        $summary = $season === null ? null : $dash->seasonRow($student->id, $season->id);

        $logs = MemorizationLog::where('student_id', $student->id)
            ->orderByDesc('log_date')->orderByDesc('id')->get();
        $mapLog = fn (MemorizationLog $log): array => [
            'id' => (int) $log->id,
            'log_date' => $log->log_date->toDateString(),
            'log_mode' => $log->log_mode,
            'thumn_amount' => (float) $log->thumn_amount,
            'hizb_no' => $log->hizb_no === null ? null : (float) $log->hizb_no,
            'thumn_no' => $log->thumn_no === null ? null : (int) $log->thumn_no,
            'surah_ref' => $log->surah_from === null ? null : (int) $log->surah_from,
            'ayah_from' => $log->ayah_from === null ? null : (int) $log->ayah_from,
            'ayah_to' => $log->ayah_to === null ? null : (int) $log->ayah_to,
        ];
        $items = $logs->map($mapLog)->all();
        $last = $items[0] ?? null;

        $exams = Exam::where('student_id', $student->id)
            ->with(['questions' => fn ($q) => $q->orderBy('sort_order')->orderBy('question_no')])
            ->orderByDesc('exam_date')->orderByDesc('id')->limit(10)->get();

        $mode = $student->memorization_mode;
        $current = ['mode' => $mode, 'hizb' => null, 'thumn' => null, 'surah' => null,
            'ayah_from' => null, 'ayah_to' => null, 'source' => null];
        foreach ($exams as $exam) {
            foreach ($exam->questions as $q) {
                /** @var ExamQuestion $q */
                if ($mode === 'thumn' && $q->hizb_ref !== null) {
                    $current = ['mode' => 'thumn', 'hizb' => (float) $q->hizb_ref,
                        'thumn' => $q->thumn_ref === null ? null : (int) $q->thumn_ref,
                        'surah' => null, 'ayah_from' => null, 'ayah_to' => null, 'source' => 'last_exam'];
                    break 2;
                }
                if ($mode === 'surah' && $q->surah_ref !== null) {
                    $current = ['mode' => 'surah', 'hizb' => null, 'thumn' => null,
                        'surah' => (int) $q->surah_ref,
                        'ayah_from' => $q->ayah_from === null ? null : (int) $q->ayah_from,
                        'ayah_to' => $q->ayah_to === null ? null : (int) $q->ayah_to,
                        'source' => 'last_exam'];
                    break 2;
                }
            }
        }
        if ($current['source'] === null && $last !== null) {
            if ($mode === 'thumn' && $last['hizb_no'] !== null) {
                $current = ['mode' => 'thumn', 'hizb' => $last['hizb_no'], 'thumn' => $last['thumn_no'],
                    'surah' => null, 'ayah_from' => null, 'ayah_to' => null, 'source' => 'last_log'];
            } elseif ($mode === 'surah' && $last['surah_ref'] !== null) {
                $current = ['mode' => 'surah', 'hizb' => null, 'thumn' => null, 'surah' => $last['surah_ref'],
                    'ayah_from' => $last['ayah_from'], 'ayah_to' => $last['ayah_to'], 'source' => 'last_log'];
            }
        }

        $seasonThumn = $season === null
            ? (float) $logs->sum('thumn_amount')
            : (float) MemorizationLog::where('student_id', $student->id)
                ->where('season_id', $season->id)->sum('thumn_amount');

        return $this->ok([
            'student' => new StudentResource($student),
            'group' => $student->group === null ? null : new GroupResource($student->group),
            'season' => $season === null ? null : ['id' => (int) $season->id, 'name' => $season->name],
            'summary' => $summary,
            'memorization' => ['last' => $last, 'items' => $items, 'total_thumn' => $seasonThumn],
            'exams' => ExamResource::collection($exams)->resolve(),
            'current' => $current,
        ]);
    }

    /**
     * Show one pupil with group and linked account.
     */
    public function show(Student $student): JsonResponse
    {
        $this->authorize('view', $student);

        return $this->ok(new StudentResource($student->load(['group', 'user'])));
    }

    /**
     * Update a pupil (placement, level, status, memorization mode, …).
     *
     * Only admins may move a pupil between centers (used to repair NULL-center
     * rows). A new group must belong to the pupil's center.
     */
    public function update(UpdateStudentRequest $request, Student $student): JsonResponse
    {
        $data = $request->validated();
        // §2.1: only non-admins are barred from moving the center; admins may
        // repair NULL-center pupils through the UI.
        if ($request->user()->role !== 'admin') {
            unset($data['center_id']);
        }

        if (! empty($data['group_id'])) {
            $group = Group::findOrFail($data['group_id']);
            $targetCenter = $data['center_id'] ?? $student->center_id;
            if ((int) $group->center_id !== (int) $targetCenter) {
                return $this->fail('Group belongs to another center.', 422);
            }
        }

        DB::transaction(function () use ($student, $data) {
            $student->update($data);
            // A level or center move must re-place the pupil: the per-season
            // row follows, and the pointer lands on the mapped season level.
            if (array_key_exists('level_id', $data) || array_key_exists('center_id', $data)) {
                $fresh = $student->fresh();
                if ($fresh->level_id !== null) {
                    $placed = StudentLevel::place($fresh, (int) $fresh->level_id);
                    if ($placed !== null && $placed !== (int) $fresh->level_id) {
                        $fresh->update(['level_id' => $placed]);
                    }
                }
            }
        });

        return $this->ok(new StudentResource($student->fresh(['group'])));
    }

    /**
     * Delete a pupil row. The login account is kept.
     */
    public function destroy(Student $student): JsonResponse
    {
        $this->authorize('delete', $student);
        $student->delete();

        return $this->ok(null, 'Deleted.');
    }
}
