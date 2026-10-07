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
