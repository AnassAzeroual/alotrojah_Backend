<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
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
    public function __construct(private ScoringService $scoring, private DashboardService $dashboard) {}

    /** @return array student_id => weekly total */
    public function storeScores(User $teacher, int $sessionId, array $rows): array
    {
        $session = Session::findOrFail($sessionId);
        if ($teacher->role !== 'admin' && $teacher->teacher_type === 'murajaa') {
            abort(403, 'Review teachers enter murajaa cycles, not weekly scores.');
        }
        $modules = ScoringModule::where('is_active', true)->where('scope', 'weekly')->get()->keyBy('code');

        $errors = []; $clean = [];
        foreach ($rows as $i => $r) {
            $p = "records.$i";
            $student = Student::find($r['student_id'] ?? null);
            if (! $student) { $errors["$p.student_id"] = 'Unknown student.'; continue; }
            if ($teacher->role !== 'admin' && (int) $student->center_id !== (int) $teacher->center_id) {
                $errors["$p.student_id"] = 'Student is in another center.'; continue;
            }
            $module = $modules[$r['module_code'] ?? ''] ?? null;
            if (! $module) { $errors["$p.module_code"] = 'Unknown or inactive module.'; continue; }
            $score = $r['score'] ?? null;
            if (! is_numeric($score) || $score < 0 || $score > (float) $module->max_points) {
                $errors["$p.score"] = "Must be between 0 and {$module->max_points}."; continue;
            }
            $clean[] = [$student, $module, (float) $score];
        }
        if ($errors) throw ValidationException::withMessages($errors);

        $totals = [];
        DB::transaction(function () use ($clean, $session, $teacher, &$totals) {
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
        });

        return $totals;
    }

    public function storeAttendance(User $teacher, int $sessionId, array $rows): array
    {
        $session = Session::findOrFail($sessionId);
        $valid = array_column(AttendanceStatus::cases(), 'value');

        $errors = []; $clean = [];
        foreach ($rows as $i => $r) {
            $p = "records.$i";
            $student = Student::find($r['student_id'] ?? null);
            if (! $student) { $errors["$p.student_id"] = 'Unknown student.'; continue; }
            if ($teacher->role !== 'admin' && (int) $student->center_id !== (int) $teacher->center_id) {
                $errors["$p.student_id"] = 'Student is in another center.'; continue;
            }
            if (! in_array($r['status'] ?? null, $valid, true)) { $errors["$p.status"] = 'Invalid status.'; continue; }
            $clean[] = [$student, $r['status'], $r['notes'] ?? null];
        }
        if ($errors) throw ValidationException::withMessages($errors);

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
