<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreExamRequest;
use App\Http\Requests\UpdateExamRequest;
use App\Http\Resources\ExamResource;
use App\Models\Exam;
use App\Models\Student;
use App\Models\Term;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExamController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Exam::class);
        $me = $request->user();

        $q = Exam::orderByDesc('exam_date')->orderByDesc('id');
        if ($me->role !== 'admin') {
            $q->whereHas('student', fn ($s) => $s->where('students.center_id', (int) $me->center_id));
        }
        foreach (['student_id', 'term_id', 'season_id', 'exam_type'] as $f) {
            if ($request->filled($f)) $q->where($f, $request->input($f));
        }

        return $this->ok(ExamResource::collection($q->paginate(50))->response()->getData(true));
    }

    public function store(StoreExamRequest $request): JsonResponse
    {
        $data = $request->validated();
        $me = $request->user();

        $student = Student::findOrFail($data['student_id']);
        if ($me->role !== 'admin' && (int) $student->center_id !== (int) $me->center_id) {
            return $this->fail('Student is in another center.', 403);
        }
        if ($data['exam_type'] === 'final_season') {
            $data['term_id'] = null;
        } else {
            $data['season_id'] = Term::findOrFail($data['term_id'])->season_id;
        }
        // teachers/examiners always sign their own exams
        if (in_array($me->role, ['teacher', 'examiner'], true)) $data['examiner_id'] = $me->id;
        else $data['examiner_id'] ??= $me->id;

        return $this->created(new ExamResource(Exam::create($data)));
    }

    public function show(Exam $exam): JsonResponse
    {
        $this->authorize('view', $exam);

        return $this->ok(new ExamResource($exam->load(['questions' => fn ($q) => $q->orderBy('sort_order')->orderBy('question_no')])));
    }

    public function update(UpdateExamRequest $request, Exam $exam): JsonResponse
    {
        $exam->update($request->validated());

        return $this->ok(new ExamResource($exam->fresh('questions')));
    }

    public function destroy(Exam $exam): JsonResponse
    {
        $this->authorize('delete', $exam);
        $exam->delete();

        return $this->ok(null, 'Deleted.');
    }
}
