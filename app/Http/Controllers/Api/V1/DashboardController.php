<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Student;
use App\Services\DashboardService;
use App\Services\ScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function season(Request $request, DashboardService $dash): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'season_id' => ['required', 'integer', 'exists:academic_seasons,id'],
        ]);
        $student = Student::findOrFail($data['student_id']);
        $this->authorize('view', $student);

        return $this->ok($dash->seasonRow($student->id, $data['season_id']));
    }

    public function weekly(Request $request, DashboardService $dash): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'season_id' => ['required', 'integer', 'exists:academic_seasons,id'],
        ]);
        $this->authorize('view', Student::findOrFail($data['student_id']));

        return $this->ok($dash->weeklyProgress($data['student_id'], $data['season_id']));
    }

    public function center(Request $request, DashboardService $dash): JsonResponse
    {
        $data = $request->validate([
            'center_id' => ['required', 'integer', 'exists:centers,id'],
            'season_id' => ['required', 'integer', 'exists:academic_seasons,id'],
        ]);
        $me = $request->user();
        if ($me->role !== 'admin' && (int) $data['center_id'] !== (int) $me->center_id) {
            return $this->fail('Another center.', 403);
        }

        $cards = $dash->centerCards($data['center_id'], $data['season_id']);
        $honors = DB::table('term_results as r')->join('students as s', 's.id', '=', 'r.student_id')
            ->where('s.center_id', $data['center_id'])->where('r.season_id', $data['season_id'])
            ->selectRaw('r.honor_flag, COUNT(*) as n')->groupBy('r.honor_flag')->get();
        $attendance = DB::table('attendance as a')->join('students as s', 's.id', '=', 'a.student_id')
            ->where('s.center_id', $data['center_id'])->where('a.season_id', $data['season_id'])
            ->selectRaw('a.status, COUNT(*) as n')->groupBy('a.status')->get();

        return $this->ok(['cards' => $cards, 'honors' => $honors, 'attendance' => $attendance]);
    }

    /** Transparent final average: components + divisor + result. */
    public function final(Request $request, ScoringService $scoring): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'season_id' => ['required', 'integer', 'exists:academic_seasons,id'],
        ]);
        $this->authorize('view', Student::findOrFail($data['student_id']));

        $avgs = $scoring->seasonAvgs($data['student_id'], $data['season_id']);
        // divisor counts term_batch + final_season only (hizb_completion is excluded by design)
        $quizzes = DB::table('v_term_quiz_avgs')
            ->where('student_id', $data['student_id'])->where('season_id', $data['season_id'])
            ->whereIn('exam_type', ['term_batch', 'final_season'])
            ->orderBy('term_id')->get();

        return $this->ok([
            'inputs' => $avgs,
            'quizzes' => $quizzes,
            'divisor' => 2 + $quizzes->count(),
            'final' => $scoring->finalAverage($data['student_id'], $data['season_id']),
        ]);
    }
}
