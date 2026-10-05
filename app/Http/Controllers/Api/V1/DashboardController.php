<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\CenterSeasonRequest;
use App\Http\Requests\StudentSeasonRequest;
use App\Http\Resources\StudentResource;
use App\Models\AcademicSeason;
use App\Models\Student;
use App\Services\DashboardService;
use App\Services\ScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * §2.16: the student's own dashboard. No parameters to tamper with — the
     * pupil is resolved from the token and the season is the current one.
     * Returns null when no pupil record is linked to the account.
     */
    public function me(Request $request, DashboardService $dash, ScoringService $scoring): JsonResponse
    {
        $me = $request->user();
        abort_unless($me->role === 'student', 403);
        $pupil = Student::where('user_id', $me->id)->first();
        if (! $pupil) return $this->ok(null);
        $seasonId = AcademicSeason::where('is_current', true)->value('id')
            ?? AcademicSeason::max('id');
        if (! $seasonId) {
            return $this->ok([
                'student' => new StudentResource($pupil->load('group')),
                'season' => null, 'weekly' => [], 'final' => null,
            ]);
        }

        return $this->ok([
            'student' => new StudentResource($pupil->load('group')),
            'season' => $dash->seasonRow($pupil->id, $seasonId),
            'weekly' => $dash->weeklyProgress($pupil->id, $seasonId),
            'final' => $scoring->finalAverage($pupil->id, $seasonId),
        ]);
    }

    public function season(StudentSeasonRequest $request, DashboardService $dash): JsonResponse
    {
        $data = $request->validated();
        $student = Student::findOrFail($data['student_id']);
        $this->authorize('view', $student);

        return $this->ok($dash->seasonRow($student->id, $data['season_id']));
    }

    public function weekly(StudentSeasonRequest $request, DashboardService $dash): JsonResponse
    {
        $data = $request->validated();
        $this->authorize('view', Student::findOrFail($data['student_id']));

        return $this->ok($dash->weeklyProgress($data['student_id'], $data['season_id']));
    }

    public function center(CenterSeasonRequest $request, DashboardService $dash): JsonResponse
    {
        $data = $request->validated();

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
    public function final(StudentSeasonRequest $request, ScoringService $scoring): JsonResponse
    {
        $data = $request->validated();
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
