<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StudentTermRequest;
use App\Models\Exam;
use App\Models\MurajaaReview;
use App\Models\ScoringModule;
use App\Models\Session;
use App\Models\Student;
use App\Models\Term;
use App\Models\TermResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Read-only proof for the four term-result boxes.
 *
 * Hifz is every non-cancelled session (missing score = 0); Murajaa is the
 * official review cycles; Exam is the latest term_batch exam and its questions;
 * General reports the stored official value and whether it still mirrors the
 * term exam.
 */
class TermBreakdownController extends Controller
{
    /**
     * @response array{success: bool, message: null, data: array{student: array{id: int, full_name: string}, term: array{id: int, name_ar: string}, hifz: array{modules: list<array{code: string, name_ar: string, max_points: float}>, sessions: int, scored_sessions: int, sum: float, average: float|null, rows: list<array{session_id: int, session_number: int, date: string|null, status: string, modules: list<array{code: string, name_ar: string, max_points: float, score: float|null}>, total: float, has_score: bool}>}, murajaa: array{count: int, average: float|null, rows: list<array{id: int, week_from: int, week_to: int, weeks_covered: int|null, score: float|null, reviewed_at: string|null, hizb_from: float|null, hizb_to: float|null}>}, exam: array{id: int, exam_date: string|null, overall_avg: float|null, sum: float, max: float, questions: list<array{question_no: int, prompt_text: string|null, model_type: string|null, max_score: float|null, score: float|null}>}|null, general: array{value: float|null, source: string}}}
     */
    public function __invoke(StudentTermRequest $request): JsonResponse
    {
        $data = $request->validated();
        $student = Student::findOrFail($data['student_id']);
        $this->authorize('view', $student);
        $term = Term::findOrFail($data['term_id']);

        $modules = ScoringModule::effectiveFor((int) $student->center_id)
            ->filter(fn ($m): bool => $m->scope === 'weekly' && (bool) $m->is_in_weekly_total && (bool) $m->is_active)
            ->sortBy('sort_order')
            ->values();

        $sessions = Session::where('term_id', $term->id)
            ->where('status', '!=', 'cancelled')
            ->orderBy('session_number_global')
            ->get(['id', 'session_number_global', 'planned_date', 'status']);
        $sessionIds = $sessions->pluck('id');
        $moduleIds = $modules->pluck('id');

        $scores = $sessionIds->isEmpty() || $moduleIds->isEmpty()
            ? collect()
            : DB::table('session_scores')
                ->where('student_id', $student->id)
                ->whereIn('session_id', $sessionIds)
                ->whereIn('module_id', $moduleIds)
                ->get(['session_id', 'module_id', 'score'])
                ->groupBy('session_id');

        $hifzRows = $sessions->map(function (Session $session) use ($modules, $scores): array {
            $own = ($scores[$session->id] ?? collect())->keyBy('module_id');
            $moduleRows = $modules->map(function (ScoringModule $module) use ($own): array {
                $score = $own[$module->id]->score ?? null;

                return [
                    'code' => $module->code,
                    'name_ar' => $module->name_ar,
                    'max_points' => (float) $module->max_points,
                    'score' => $score === null ? null : (float) $score,
                ];
            })->values()->all();

            $total = round(array_sum(array_map(
                fn (array $m): float => $m['score'] ?? 0,
                $moduleRows,
            )), 2);

            return [
                'session_id' => $session->id,
                'session_number' => $session->session_number_global,
                'date' => $session->planned_date?->toDateString(),
                'status' => $session->status,
                'modules' => $moduleRows,
                'total' => $total,
                'has_score' => array_any($moduleRows, fn (array $m): bool => $m['score'] !== null),
            ];
        })->values();

        $hifzSum = round((float) $hifzRows->sum('total'), 2);
        $sessionCount = $sessions->count();

        $reviews = MurajaaReview::where('student_id', $student->id)
            ->where('term_id', $term->id)
            ->orderBy('week_from')
            ->get();
        $murajaaAverage = $reviews->avg('score');

        $exam = Exam::where('student_id', $student->id)
            ->where('term_id', $term->id)
            ->where('exam_type', 'term_batch')
            ->orderByDesc('exam_date')
            ->orderByDesc('id')
            ->first();
        $questions = $exam === null
            ? collect()
            : $exam->questions()->orderBy('sort_order')->orderBy('question_no')->get();

        $result = TermResult::where('student_id', $student->id)->where('term_id', $term->id)->first();
        $general = $result?->general_avg;
        $generalSource = 'none';
        if ($general !== null) {
            $generalSource = $exam?->overall_avg !== null
                && abs((float) $general - (float) $exam->overall_avg) < 0.005
                    ? 'term_exam'
                    : 'official';
        }

        return $this->ok([
            'student' => ['id' => $student->id, 'full_name' => $student->full_name],
            'term' => ['id' => $term->id, 'name_ar' => $term->name_ar],
            'hifz' => [
                'modules' => $modules->map(fn (ScoringModule $m): array => [
                    'code' => $m->code, 'name_ar' => $m->name_ar, 'max_points' => (float) $m->max_points,
                ])->values(),
                'sessions' => $sessionCount,
                'scored_sessions' => $hifzRows->where('has_score', true)->count(),
                'sum' => $hifzSum,
                'average' => $sessionCount === 0 ? null : round($hifzSum / $sessionCount, 2),
                'rows' => $hifzRows,
            ],
            'murajaa' => [
                'count' => $reviews->count(),
                'average' => $murajaaAverage === null ? null : round((float) $murajaaAverage, 2),
                'rows' => $reviews->map(fn (MurajaaReview $r): array => [
                    'id' => $r->id,
                    'week_from' => $r->week_from,
                    'week_to' => $r->week_to,
                    'weeks_covered' => $r->weeks_covered,
                    'score' => (float) $r->score,
                    'reviewed_at' => $r->reviewed_at?->toDateString(),
                    'hizb_from' => $r->hizb_from,
                    'hizb_to' => $r->hizb_to,
                ])->values(),
            ],
            'exam' => $exam === null ? null : [
                'id' => $exam->id,
                'exam_date' => $exam->exam_date?->toDateString(),
                'overall_avg' => $exam->overall_avg === null ? null : (float) $exam->overall_avg,
                'sum' => round((float) $questions->sum(fn ($q): float => (float) ($q->score ?? 0)), 2),
                'max' => round((float) $questions->sum('max_score'), 2),
                'questions' => $questions->map(fn ($q): array => [
                    'question_no' => $q->question_no,
                    'prompt_text' => $q->prompt_text,
                    'model_type' => $q->model_type,
                    'max_score' => $q->max_score === null ? null : (float) $q->max_score,
                    'score' => $q->score === null ? null : (float) $q->score,
                ])->values(),
            ],
            'general' => [
                'value' => $general === null ? null : (float) $general,
                'source' => $generalSource,
            ],
        ]);
    }
}
