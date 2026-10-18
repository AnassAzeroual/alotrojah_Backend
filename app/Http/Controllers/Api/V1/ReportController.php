<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StudentSeasonRequest;
use App\Http\Requests\StudentTermRequest;
use App\Http\Resources\AttendanceRateRow;
use App\Http\Resources\MurajaaReviewResource;
use App\Http\Resources\SeasonResultResource;
use App\Http\Resources\TermPlanResource;
use App\Http\Resources\TermQuizAvgRow;
use App\Http\Resources\TermResultResource;
use App\Http\Resources\WeeklyProgressRow;
use App\Models\SeasonResult;
use App\Models\Student;
use App\Models\Term;
use App\Models\TermResult;
use App\Services\ScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/** Printable pages consume these composites (book fidelity + better). */
class ReportController extends Controller
{
    /**
     * Printable term report for one pupil: result, plan, quiz averages,
     * attendance rate, weekly progress, murajaa cycles.
     *
     * @response array{success: bool, message: string|null, data: array{student: array{id: int, full_name: string}, term: array{id: int, name_ar: string}, result: TermResultResource|null, plan: TermPlanResource|null, quizzes: list<TermQuizAvgRow>, attendance: AttendanceRateRow|null, weekly: list<WeeklyProgressRow>, murajaa_cycles: list<MurajaaReviewResource>}}
     */
    public function term(StudentTermRequest $request, ScoringService $scoring): JsonResponse
    {
        $data = $request->validated();
        $student = Student::findOrFail($data['student_id']);
        $this->authorize('view', $student);
        $term = Term::findOrFail($data['term_id']);

        $result = TermResult::where('student_id', $student->id)->where('term_id', $term->id)->first();
        $plan = $student->termPlans()->where('term_id', $term->id)->first();
        $attendance = DB::table('v_attendance_rate')
            ->where('student_id', $student->id)->where('term_id', $term->id)->first();

        return $this->ok([
            'student' => ['id' => $student->id, 'full_name' => $student->full_name],
            'term' => ['id' => $term->id, 'name_ar' => $term->name_ar],
            'result' => $result === null ? null : new TermResultResource($result),
            'plan' => $plan === null ? null : new TermPlanResource($plan),
            'quizzes' => DB::table('v_term_quiz_avgs')->where('student_id', $student->id)->where('term_id', $term->id)->get()
                ->map(static fn (object $row): TermQuizAvgRow => new TermQuizAvgRow($row))->values()->all(),
            'attendance' => $attendance === null ? null : new AttendanceRateRow($attendance),
            'weekly' => DB::table('v_weekly_progress')->where('student_id', $student->id)->where('term_id', $term->id)->orderBy('week_id')->get()
                ->map(static fn (object $row): WeeklyProgressRow => new WeeklyProgressRow($row))->values()->all(),
            'murajaa_cycles' => MurajaaReviewResource::collection(
                $student->murajaaReviews()->where('term_id', $term->id)->orderBy('week_from')->get()
            )->resolve(),
        ]);
    }

    /**
     * Printable season report for one pupil: season/term results, scoring
     * inputs, final average, quiz averages, honor flag.
     *
     * @response array{success: bool, message: string|null, data: array{student: array{id: int, full_name: string}, result: SeasonResultResource|null, term_results: list<TermResultResource>, inputs: array{avg_murajaa: float|null, avg_weekly: float|null, avg_sarraj: float|null}, final: float|null, quizzes: list<TermQuizAvgRow>, honor: 'none'|'tashji3'|'intibah'|null}}
     */
    public function season(StudentSeasonRequest $request, ScoringService $scoring): JsonResponse
    {
        $data = $request->validated();
        $student = Student::findOrFail($data['student_id']);
        $this->authorize('view', $student);

        $avgs = $scoring->seasonAvgs($student->id, $data['season_id']);
        $result = SeasonResult::where('student_id', $student->id)->where('season_id', $data['season_id'])->first();

        return $this->ok([
            'student' => ['id' => $student->id, 'full_name' => $student->full_name],
            'result' => $result === null ? null : new SeasonResultResource($result),
            'term_results' => TermResultResource::collection(
                TermResult::where('student_id', $student->id)->where('season_id', $data['season_id'])->orderBy('term_id')->get()
            )->resolve(),
            'inputs' => $avgs,
            'final' => $scoring->finalAverage($student->id, $data['season_id']),
            'quizzes' => DB::table('v_term_quiz_avgs')->where('student_id', $student->id)->where('season_id', $data['season_id'])->orderBy('term_id')->get()
                ->map(static fn (object $row): TermQuizAvgRow => new TermQuizAvgRow($row))->values()->all(),
            'honor' => $result?->honor_flag,
        ]);
    }
}
