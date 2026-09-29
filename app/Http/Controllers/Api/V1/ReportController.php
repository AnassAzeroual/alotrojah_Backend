<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StudentSeasonRequest;
use App\Http\Requests\StudentTermRequest;
use App\Models\SeasonResult;
use App\Models\Student;
use App\Models\Term;
use App\Models\TermResult;
use App\Services\ScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Printable pages consume these composites (book fidelity + better). */
class ReportController extends Controller
{
    public function term(StudentTermRequest $request, ScoringService $scoring): JsonResponse
    {
        $data = $request->validated();
        $student = Student::findOrFail($data['student_id']);
        $this->authorize('view', $student);
        $term = Term::findOrFail($data['term_id']);

        return $this->ok([
            'student' => ['id' => $student->id, 'full_name' => $student->full_name],
            'term' => ['id' => $term->id, 'name_ar' => $term->name_ar],
            'result' => TermResult::where('student_id', $student->id)->where('term_id', $term->id)->first(),
            'plan' => $student->termPlans()->where('term_id', $term->id)->first(),
            'quizzes' => DB::table('v_term_quiz_avgs')->where('student_id', $student->id)->where('term_id', $term->id)->get(),
            'attendance' => DB::table('v_attendance_rate')->where('student_id', $student->id)->where('term_id', $term->id)->first(),
            'weekly' => DB::table('v_weekly_progress')->where('student_id', $student->id)->where('term_id', $term->id)->orderBy('week_id')->get(),
            'murajaa_cycles' => $student->murajaaReviews()->where('term_id', $term->id)->orderBy('week_from')->get(),
        ]);
    }

    public function season(StudentSeasonRequest $request, ScoringService $scoring): JsonResponse
    {
        $data = $request->validated();
        $student = Student::findOrFail($data['student_id']);
        $this->authorize('view', $student);

        $avgs = $scoring->seasonAvgs($student->id, $data['season_id']);

        return $this->ok([
            'student' => ['id' => $student->id, 'full_name' => $student->full_name],
            'result' => SeasonResult::where('student_id', $student->id)->where('season_id', $data['season_id'])->first(),
            'term_results' => TermResult::where('student_id', $student->id)->where('season_id', $data['season_id'])->orderBy('term_id')->get(),
            'inputs' => $avgs,
            'final' => $scoring->finalAverage($student->id, $data['season_id']),
            'quizzes' => DB::table('v_term_quiz_avgs')->where('student_id', $student->id)->where('season_id', $data['season_id'])->orderBy('term_id')->get(),
            'honor' => SeasonResult::where('student_id', $student->id)->where('season_id', $data['season_id'])->value('honor_flag'),
        ]);
    }
}
