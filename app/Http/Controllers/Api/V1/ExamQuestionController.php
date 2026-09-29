<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreQuestionsBulkRequest;
use App\Http\Requests\UpdateQuestionRequest;
use App\Http\Resources\ExamQuestionResource;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\Surah;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExamQuestionController extends Controller
{
    /** Flexible count (Q16): append a batch; overall_avg recomputed. */
    public function bulk(StoreQuestionsBulkRequest $request, Exam $exam): JsonResponse
    {
        $errors = [];
        foreach ($request->input('questions') as $i => $row) {
            if (ExamQuestion::where('exam_id', $exam->id)->where('question_no', $row['question_no'])->exists()) {
                $errors["questions.$i.question_no"] = 'Already used in this exam.';
            }
            if (! empty($row['surah_ref']) && (! empty($row['ayah_from']) || ! empty($row['ayah_to']))) {
                $max = (int) Surah::where('id', $row['surah_ref'])->value('ayahs_count');
                foreach (['ayah_from', 'ayah_to'] as $a) {
                    if (! empty($row[$a]) && ($row[$a] < 1 || $row[$a] > $max)) {
                        $errors["questions.$i.$a"] = "Ayah out of range (1-$max).";
                    }
                }
                if (! empty($row['ayah_from']) && ! empty($row['ayah_to']) && $row['ayah_to'] < $row['ayah_from']) {
                    $errors["questions.$i.ayah_to"] = 'Must be after ayah_from.';
                }
            }
        }
        if ($errors) throw ValidationException::withMessages($errors);

        $created = DB::transaction(function () use ($request, $exam) {
            $out = [];
            foreach ($request->input('questions') as $row) {
                $row['sort_order'] ??= $row['question_no'];
                $out[] = $exam->questions()->create($row);
            }
            $this->recompute($exam);

            return $out;
        });

        return $this->created(ExamQuestionResource::collection($created));
    }

    public function show(ExamQuestion $examQuestion): JsonResponse
    {
        $this->authorize('view', $examQuestion->exam);

        return $this->ok(new ExamQuestionResource($examQuestion));
    }

    public function update(UpdateQuestionRequest $request, ExamQuestion $examQuestion): JsonResponse
    {
        $examQuestion->update($request->validated());
        $this->recompute($examQuestion->exam);

        return $this->ok(new ExamQuestionResource($examQuestion->fresh()));
    }

    public function destroy(ExamQuestion $examQuestion): JsonResponse
    {
        $this->authorize('manage', $examQuestion->exam);
        $exam = $examQuestion->exam;
        $examQuestion->delete();
        $this->recompute($exam);

        return $this->ok(null, 'Deleted.');
    }

    private function recompute(Exam $exam): void
    {
        $avg = $exam->questions()->whereNotNull('score')->avg('score');
        $exam->update(['overall_avg' => $avg === null ? null : round((float) $avg, 2)]);
    }
}
