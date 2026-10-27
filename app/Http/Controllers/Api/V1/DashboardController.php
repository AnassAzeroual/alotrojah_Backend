<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\CenterSeasonRequest;
use App\Http\Requests\StudentSeasonRequest;
use App\Http\Resources\SeasonDashboardRow;
use App\Http\Resources\StudentResource;
use App\Http\Resources\TermQuizAvgRow;
use App\Http\Resources\WeeklyProgressRow;
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
     *
     * @response array{success: bool, message: string|null, data: array{student: StudentResource, season: SeasonDashboardRow|null, weekly: list<WeeklyProgressRow>, final: float|null}|null}
     */
    public function me(Request $request, DashboardService $dash, ScoringService $scoring): JsonResponse
    {
        $me = $request->user();
        abort_unless($me->role === 'student', 403);
        $pupil = Student::where('user_id', $me->id)->first();
        if (! $pupil) {
            return $this->ok(null);
        }
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

    /**
     * The season summary row for one pupil (season totals vs plan).
     *
     * @response array{success: bool, message: string|null, data: SeasonDashboardRow|null}
     */
    public function season(StudentSeasonRequest $request, DashboardService $dash): JsonResponse
    {
        $data = $request->validated();
        $student = Student::findOrFail($data['student_id']);
        $this->authorize('view', $student);

        return $this->ok($dash->seasonRow($student->id, $data['season_id']));
    }

    /**
     * One pupil's per-week progress bars for a season.
     *
     * @response array{success: bool, message: string|null, data: list<WeeklyProgressRow>}
     */
    public function weekly(StudentSeasonRequest $request, DashboardService $dash): JsonResponse
    {
        $data = $request->validated();
        $this->authorize('view', Student::findOrFail($data['student_id']));

        return $this->ok($dash->weeklyProgress($data['student_id'], $data['season_id']));
    }

    /**
     * Center-level dashboard for a season: KPI cards + honor distribution +
     * attendance breakdown.
     *
     * @response array{success: bool, message: string|null, data: array{cards: array{students: int, avg_score: float|null, avg_sarraj: float|null, avg_attendance: float|null}, honors: list<array{honor_flag: 'none'|'tashji3'|'intibah', n: int}>, attendance: list<array{status: 'present'|'absent'|'late'|'excused', n: int}>}}
     */
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

    /**
     * Transparent final average: components + divisor + result.
     *
     * @response array{success: bool, message: string|null, data: array{inputs: array{avg_murajaa: float|null, avg_weekly: float|null, avg_sarraj: float|null}, quizzes: list<TermQuizAvgRow>, divisor: int, final: float|null}}
     */
    public function final(StudentSeasonRequest $request, ScoringService $scoring): JsonResponse
    {
        $data = $request->validated();
        $this->authorize('view', Student::findOrFail($data['student_id']));

        $avgs = $scoring->seasonAvgs($data['student_id'], $data['season_id']);
        // divisor counts term_batch + final_season only (hizb_completion is excluded by design)
        $quizzes = DB::table('v_term_quiz_avgs')
            ->where('student_id', $data['student_id'])->where('season_id', $data['season_id'])
            ->whereIn('exam_type', ['term_batch', 'final_season'])
            ->orderBy('term_id')->get()
            ->map(static fn (object $row): TermQuizAvgRow => new TermQuizAvgRow($row));

        return $this->ok([
            'inputs' => $avgs,
            'quizzes' => $quizzes->values()->all(),
            'divisor' => 2 + $quizzes->count(),
            'final' => $scoring->finalAverage($data['student_id'], $data['season_id']),
        ]);
    }
}
