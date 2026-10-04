<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreQuestionsBulkRequest;
use App\Http\Requests\UpdateQuestionRequest;
use App\Http\Resources\ExamQuestionResource;
use App\Http\Resources\ExamResource;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\Surah;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExamQuestionController extends Controller
{
    /** Flexible count (Q16): append a batch; overall_avg recomputed. */
    public function bulk(StoreQuestionsBulkRequest $request, Exam $exam): JsonResponse
    {
        // preload taken numbers: one query, not one per row
        $taken = $exam->questions()->pluck('question_no')->all();
        $surahMax = Surah::whereIn('id', collect($request->input('questions'))->pluck('surah_ref')->filter()->unique()->all())
            ->pluck('ayahs_count', 'id');
        $errors = [];
        foreach ($request->input('questions') as $i => $row) {
            if (in_array($row['question_no'], $taken, true)) {
                $errors["questions.$i.question_no"] = 'Already used in this exam.';
            } else {
                $taken[] = $row['question_no'];
            }
            if (! empty($row['surah_ref']) && (! empty($row['ayah_from']) || ! empty($row['ayah_to']))) {
                $max = (int) ($surahMax[$row['surah_ref']] ?? 0);
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

        // Weighted-sum rule (Item 6): weights must total exactly 20 — per-row
        // score caps plus the resulting total, checked inside the transaction.
        foreach ($request->input('questions') as $i => $row) {
            if (isset($row['score']) && $row['score'] !== null && (float) $row['score'] > (float) $row['max_score']) {
                $errors["questions.$i.score"] = 'Score exceeds the question weight.';
            }
        }
        if ($errors) throw ValidationException::withMessages($errors);

        $created = DB::transaction(function () use ($request, $exam) {
            $out = [];
            foreach ($request->input('questions') as $row) {
                $row['sort_order'] ??= $row['question_no'];
                $out[] = $exam->questions()->create($row);
            }
            $this->assertWeightsTotal($exam);
            $this->recompute($exam);

            return $out;
        });

        return $this->created(ExamQuestionResource::collection($created));
    }

    /**
     * Atomic reweight (scores preserved): every row must belong to the exam
     * and the resulting weights must total exactly 20.
     */
    public function reweight(Request $request, Exam $exam): JsonResponse
    {
        $this->authorize('manage', $exam);
        $data = $request->validate([
            'weights' => ['required', 'array', 'min:1'],
            'weights.*.id' => ['required', 'integer'],
            'weights.*.max_score' => ['required', 'numeric', 'min:0.01', 'max:20'],
        ]);
        $ids = collect($data['weights'])->pluck('id')->all();
        $rows = $exam->questions()->whereIn('id', $ids)->get()->keyBy('id');
        if ($rows->count() !== count($ids)) {
            return $this->fail('Every weight must belong to this exam.', 422);
        }
        foreach ($data['weights'] as $w) {
            $over = $rows[$w['id']]->score !== null && (float) $rows[$w['id']]->score > (float) $w['max_score'];
            if ($over) {
                return $this->fail('A recorded score exceeds its new weight.', 422);
            }
        }
        $total = round(collect($data['weights'])->sum(fn ($w) => (float) $w['max_score']), 2);
        if (abs($total - 20) > 0.009) {
            return $this->fail("Weights must total 20 (got $total).", 422);
        }
        DB::transaction(function () use ($rows, $data) {
            foreach ($data['weights'] as $w) {
                $rows[$w['id']]->update(['max_score' => $w['max_score']]);
            }
        });

        return $this->ok(new ExamResource($exam->fresh('questions')));
    }

    public function show(ExamQuestion $examQuestion): JsonResponse
    {
        $this->authorize('view', $examQuestion->exam);

        return $this->ok(new ExamQuestionResource($examQuestion));
    }

    public function update(UpdateQuestionRequest $request, ExamQuestion $examQuestion): JsonResponse
    {
        $data = $request->validated();
        $max = array_key_exists('max_score', $data) ? (float) $data['max_score'] : (float) $examQuestion->max_score;
        if (array_key_exists('score', $data) && $data['score'] !== null && (float) $data['score'] > $max) {
            return $this->fail('Score exceeds the question weight.', 422);
        }
        if (array_key_exists('max_score', $data)) {
            $total = round((float) $examQuestion->exam->questions()->where('id', '!=', $examQuestion->id)->sum('max_score') + (float) $data['max_score'], 2);
            if (abs($total - 20) > 0.009) {
                return $this->fail("Weights must total 20 (got $total) — use question-weights to rebalance.", 422);
            }
        }
        $examQuestion->update($data);
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
        $has = $exam->questions()->whereNotNull('score')->exists();
        $sum = $has ? round((float) $exam->questions()->whereNotNull('score')->sum('score'), 2) : null;
        $exam->update(['overall_avg' => $sum]);
    }

    /** Resulting weights must total exactly 20 (called inside a transaction). */
    private function assertWeightsTotal(Exam $exam): void
    {
        $total = round((float) $exam->questions()->sum('max_score'), 2);
        if (abs($total - 20) > 0.009) {
            throw ValidationException::withMessages([
                'weights' => "Weights must total 20 (got $total).",
            ]);
        }
    }
}
