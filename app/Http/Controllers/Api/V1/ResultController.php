<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\UpsertSeasonResultRequest;
use App\Http\Requests\UpsertTermResultRequest;
use App\Http\Resources\SeasonResultResource;
use App\Http\Resources\TermResultResource;
use App\Models\SeasonResult;
use App\Models\Student;
use App\Models\TermResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResultController extends Controller
{
    /**
     * List term results (50/page).
     *
     * Students see only their own. Filters: student_id, term_id.
     *
     * @response array{success: bool, message: string|null, data: array{data: list<TermResultResource>, links: array{first: string|null, last: string|null, prev: string|null, next: string|null}, meta: array{current_page: int, from: int|null, last_page: int, links: list<array{url: string|null, label: string, active: bool}>, path: string, per_page: int, to: int|null, total: int}}}
     */
    public function indexTerms(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TermResult::class);
        $me = $request->user();

        $q = TermResult::orderBy('id');
        if ($me->role === 'student') {
            // §2.3: a student sees only their own results (linked via students.user_id).
            $q->whereHas('student', fn ($s) => $s->where('students.user_id', $me->id));
        } elseif ($me->role !== 'admin') {
            $q->whereHas('student', fn ($s) => $s->where('students.center_id', (int) $me->center_id));
        }
        if ($request->filled('student_id')) $q->where('student_id', (int) $request->input('student_id'));
        if ($request->filled('term_id')) $q->where('term_id', (int) $request->input('term_id'));

        return $this->ok(TermResultResource::collection($q->paginate(50))->response()->getData(true));
    }

    /**
     * Create or update one pupil's result for one term (one row per pair).
     *
     * The season is derived from the term. Non-admins writing for another
     * center's pupil get 403 CROSS_CENTER.
     */
    public function upsertTerm(UpsertTermResultRequest $request): JsonResponse
    {
        $data = $request->validated();
        $me = $request->user();

        $student = Student::findOrFail($data['student_id']);
        if ($me->role !== 'admin' && (int) $student->center_id !== (int) $me->center_id) {
            return $this->fail('Student is in another center.', 403);
        }
        $term = \App\Models\Term::findOrFail($data['term_id']);
        $data['season_id'] = $term->season_id;

        $result = TermResult::updateOrCreate(
            ['student_id' => $student->id, 'term_id' => $term->id], $data
        );

        return $this->created(new TermResultResource($result));
    }

    /**
     * List season results (50/page).
     *
     * Students see only their own. Filter: student_id.
     *
     * @response array{success: bool, message: string|null, data: array{data: list<SeasonResultResource>, links: array{first: string|null, last: string|null, prev: string|null, next: string|null}, meta: array{current_page: int, from: int|null, last_page: int, links: list<array{url: string|null, label: string, active: bool}>, path: string, per_page: int, to: int|null, total: int}}}
     */
    public function indexSeasons(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SeasonResult::class);
        $me = $request->user();

        $q = SeasonResult::orderBy('id');
        if ($me->role === 'student') {
            // §2.3: a student sees only their own results (linked via students.user_id).
            $q->whereHas('student', fn ($s) => $s->where('students.user_id', $me->id));
        } elseif ($me->role !== 'admin') {
            $q->whereHas('student', fn ($s) => $s->where('students.center_id', (int) $me->center_id));
        }
        if ($request->filled('student_id')) $q->where('student_id', (int) $request->input('student_id'));

        return $this->ok(SeasonResultResource::collection($q->paginate(50))->response()->getData(true));
    }

    /**
     * Create or update one pupil's result for one season (one row per pair).
     *
     * Non-admins writing for another center's pupil get 403 CROSS_CENTER.
     */
    public function upsertSeason(UpsertSeasonResultRequest $request): JsonResponse
    {
        $data = $request->validated();
        $me = $request->user();

        $student = Student::findOrFail($data['student_id']);
        if ($me->role !== 'admin' && (int) $student->center_id !== (int) $me->center_id) {
            return $this->fail('Student is in another center.', 403);
        }

        $result = SeasonResult::updateOrCreate(
            ['student_id' => $student->id, 'season_id' => $data['season_id']], $data
        );

        return $this->created(new SeasonResultResource($result));
    }
}
