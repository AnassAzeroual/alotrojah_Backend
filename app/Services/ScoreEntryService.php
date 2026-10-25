<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\MemorizationLog;
use App\Models\Scopes\CenterScope;
use App\Models\ScoringModule;
use App\Models\Session;
use App\Models\SessionScore;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * S7 daily entry. All-or-nothing bulk writes with row-indexed errors.
 * Center isolation enforced per record (sessions are season-wide, students are not).
 */
class ScoreEntryService
{
    public function __construct(
        private ScoringService $scoring,
        private DashboardService $dashboard,
        private DelegationService $delegation,
        private TermHifzResultSync $termHifz,
    ) {}

    /**
     * @return array{weekly_totals: array<int, float>, memorizations_saved: int}
     */
    public function storeScores(User $teacher, int $sessionId, array $rows, array $memorizations = []): array
    {
        $session = Session::findOrFail($sessionId);
        if ($teacher->role !== 'admin' && $teacher->teacher_type === 'murajaa') {
            abort(403, 'Review teachers enter murajaa cycles, not weekly scores.');
        }
        // Modules resolve per student center: a center's override rows win over
        // the shared defaults (copy-on-write sets). Preload both, merge per row.
        $defaults = ScoringModule::whereNull('center_id')
            ->where('is_active', true)->where('scope', 'weekly')->get()->keyBy('code');
        $overrides = ScoringModule::whereNotNull('center_id')
            ->where('is_active', true)->where('scope', 'weekly')->get()->groupBy('center_id');

        // preload: bulk must not do one student lookup per row (N+1). Both id
        // sets matter — a pupil may carry a memorization amount with no score
        // rows in the same save (live 422 2026-10-17: memorizations-only pupil
        // misreported as "Unknown student.", killing the whole batch).
        $ids = collect($rows)->pluck('student_id')
            ->merge(collect($memorizations)->pluck('student_id'))
            ->unique()->all();
        $students = Student::withoutGlobalScope(CenterScope::class)
            ->whereIn('id', $ids)
            ->get()->keyBy('id');

        $errors = [];
        $clean = [];
        foreach ($rows as $i => $r) {
            $p = "records.$i";
            $student = $students[$r['student_id'] ?? 0] ?? null;
            if (! $student) {
                $errors["$p.student_id"] = 'Unknown student.';

                continue;
            }
            if ($teacher->role !== 'admin' && (int) $student->center_id !== (int) $teacher->center_id
                && ! $this->delegation->canActAs($teacher, $student)) {
                $errors["$p.student_id"] = 'Student is in another center.';

                continue;
            }
            $set = $defaults->toBase()->merge(
                ($overrides[$student->center_id] ?? collect())->keyBy('code')
            );
            $module = $set[$r['module_code'] ?? ''] ?? null;
            if (! $module) {
                $errors["$p.module_code"] = 'Unknown or inactive module.';

                continue;
            }
            $score = $r['score'] ?? null;
            if (! is_numeric($score) || $score < 0 || $score > (float) $module->max_points) {
                $errors["$p.score"] = "Must be between 0 and {$module->max_points}.";

                continue;
            }
            $clean[] = [$student, $module, (float) $score];
        }

        // §B memorization capture: optional, per student. A blank thumn_amount
        // means "clear this session's log" (re-saves never accumulate).
        $memClean = [];
        foreach ($memorizations as $i => $m) {
            $p = "memorizations.$i";
            $student = $students[$m['student_id'] ?? 0] ?? null;
            if (! $student) {
                $errors["$p.student_id"] = 'Unknown student.';

                continue;
            }
            if ($teacher->role !== 'admin' && (int) $student->center_id !== (int) $teacher->center_id
                && ! $this->delegation->canActAs($teacher, $student)) {
                $errors["$p.student_id"] = 'Student is in another center.';

                continue;
            }
            $amount = $m['thumn_amount'] ?? null;
            if ($amount !== null && (! is_numeric($amount) || (float) $amount <= 0 || (float) $amount > 99.99)) {
                $errors["$p.thumn_amount"] = 'Must be between 0.01 and 99.99.';

                continue;
            }
            $memClean[] = [$student, $amount !== null ? (float) $amount : null];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $totals = [];
        $memSaved = 0;
        DB::transaction(function () use ($clean, $memClean, $session, $teacher, &$totals, &$memSaved) {
            foreach ($clean as [$student, $module, $score]) {
                SessionScore::updateOrCreate(
                    ['student_id' => $student->id, 'session_id' => $session->id, 'module_id' => $module->id],
                    ['season_id' => $session->season_id, 'term_id' => $session->term_id, 'week_id' => $session->week_id,
                        'log_date' => $session->planned_date ?? now()->toDateString(),
                        'score' => $score, 'entered_by' => $teacher->id]
                );
                $totals[$student->id] = $this->scoring->weeklyTotal($student->id, $session->id);
                $this->dashboard->bust($student->id, $session->season_id);
            }
            $studentIds = [];
            foreach ($clean as [$student]) {
                $studentIds[$student->id] = true;
            }
            foreach (array_keys($studentIds) as $studentId) {
                $this->termHifz->apply($studentId, (int) $session->term_id);
            }
            foreach ($memClean as [$student, $amount]) {
                MemorizationLog::where('student_id', $student->id)
                    ->where('session_id', $session->id)->delete();
                if ($amount !== null) {
                    MemorizationLog::create([
                        'student_id' => $student->id,
                        'season_id' => $session->season_id,
                        'term_id' => $session->term_id,
                        'week_id' => $session->week_id,
                        'session_id' => $session->id,
                        'log_date' => $session->planned_date ?? now()->toDateString(),
                        'log_mode' => $student->memorization_mode ?? 'thumn',
                        'thumn_amount' => $amount,
                        'created_by' => $teacher->id,
                    ]);
                    $memSaved++;
                    $this->dashboard->bust($student->id, $session->season_id);
                }
            }
        });

        return ['weekly_totals' => $totals, 'memorizations_saved' => $memSaved];
    }

    public function storeAttendance(User $teacher, int $sessionId, array $rows): array
    {
        $session = Session::findOrFail($sessionId);
        $valid = array_column(AttendanceStatus::cases(), 'value');

        $students = Student::withoutGlobalScope(CenterScope::class)
            ->whereIn('id', collect($rows)->pluck('student_id')->unique()->all())
            ->get()->keyBy('id');

        $errors = [];
        $clean = [];
        foreach ($rows as $i => $r) {
            $p = "records.$i";
            $student = $students[$r['student_id'] ?? 0] ?? null;
            if (! $student) {
                $errors["$p.student_id"] = 'Unknown student.';

                continue;
            }
            if ($teacher->role !== 'admin' && (int) $student->center_id !== (int) $teacher->center_id
                && ! $this->delegation->canActAs($teacher, $student)) {
                $errors["$p.student_id"] = 'Student is in another center.';

                continue;
            }
            if (! in_array($r['status'] ?? null, $valid, true)) {
                $errors["$p.status"] = 'Invalid status.';

                continue;
            }
            $clean[] = [$student, $r['status'], $r['notes'] ?? null];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $ids = [];
        DB::transaction(function () use ($clean, $session, $teacher, &$ids) {
            foreach ($clean as [$student, $status, $notes]) {
                $a = Attendance::updateOrCreate(
                    ['student_id' => $student->id, 'session_id' => $session->id],
                    ['season_id' => $session->season_id, 'term_id' => $session->term_id, 'week_id' => $session->week_id,
                        'status' => $status, 'notes' => $notes, 'marked_by' => $teacher->id]
                );
                $ids[] = $a->id;
            }
        });

        return $ids;
    }
}
