<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Item 6: weighted-sum scoring (weights total exactly 20). Transaction-wrapped. */
class ExamWeightsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    private function makeExam(): int
    {
        $this->actingAs(User::find(1), 'api');
        $r = $this->postJson('/api/v1/exams', [
            'student_id' => 1, 'exam_type' => 'term_batch', 'term_id' => 1,
        ])->assertCreated();

        return $r->json('data.id');
    }

    private function bulk(int $examId, array $questions, int $status = 201): void
    {
        $this->actingAs(User::find(1), 'api')
            ->postJson("/api/v1/exams/{$examId}/questions", ['questions' => $questions])
            ->assertStatus($status);
    }

    public function test_bulk_rejects_weights_not_totalling_20(): void
    {
        $examId = $this->makeExam();
        $this->bulk($examId, [
            ['question_no' => 1, 'max_score' => 10],
            ['question_no' => 2, 'max_score' => 5],
        ], 422);
        $this->assertEquals(0, DB::table('exam_questions')->where('exam_id', $examId)->count());
    }

    public function test_bulk_happy_path_sums_overall_avg(): void
    {
        $examId = $this->makeExam();
        $this->bulk($examId, [
            ['question_no' => 1, 'max_score' => 7, 'score' => 7],
            ['question_no' => 2, 'max_score' => 7, 'score' => 6],
            ['question_no' => 3, 'max_score' => 6, 'score' => 6],
        ]);
        $this->assertEquals(19.0, (float) Exam::find($examId)->overall_avg);
    }

    public function test_score_over_max_is_rejected(): void
    {
        $examId = $this->makeExam();
        $this->bulk($examId, [
            ['question_no' => 1, 'max_score' => 7],
            ['question_no' => 2, 'max_score' => 7],
            ['question_no' => 3, 'max_score' => 6],
        ]);
        $qid = DB::table('exam_questions')->where('exam_id', $examId)->where('question_no', 1)->value('id');
        $this->actingAs(User::find(1), 'api')
            ->patchJson("/api/v1/exam-questions/{$qid}", ['score' => 8])
            ->assertStatus(422);
        $this->actingAs(User::find(1), 'api')
            ->patchJson("/api/v1/exam-questions/{$qid}", ['score' => 7])
            ->assertOk();
    }

    public function test_single_max_edit_breaking_total_is_rejected(): void
    {
        $examId = $this->makeExam();
        $this->bulk($examId, [
            ['question_no' => 1, 'max_score' => 10],
            ['question_no' => 2, 'max_score' => 10],
        ]);
        $qid = DB::table('exam_questions')->where('exam_id', $examId)->where('question_no', 1)->value('id');
        $this->actingAs(User::find(1), 'api')
            ->patchJson("/api/v1/exam-questions/{$qid}", ['max_score' => 5])
            ->assertStatus(422);
    }

    public function test_delete_is_allowed_and_reweight_fixes_total(): void
    {
        $examId = $this->makeExam();
        $this->bulk($examId, [
            ['question_no' => 1, 'max_score' => 10, 'score' => 9],
            ['question_no' => 2, 'max_score' => 10, 'score' => 8],
        ]);
        $qid = DB::table('exam_questions')->where('exam_id', $examId)->where('question_no', 2)->value('id');
        // delete breaks the total (10 left) — allowed, avg follows the remainder
        $this->actingAs(User::find(1), 'api')
            ->deleteJson("/api/v1/exam-questions/{$qid}")
            ->assertOk();
        $this->assertEquals(9.0, (float) Exam::find($examId)->fresh()->overall_avg);
        // atomic reweight of the remainder back to 20
        $q1 = DB::table('exam_questions')->where('exam_id', $examId)->value('id');
        $this->actingAs(User::find(1), 'api')
            ->putJson("/api/v1/exams/{$examId}/question-weights", [
                'weights' => [['id' => $q1, 'max_score' => 20]],
            ])->assertOk();
        $this->assertEquals(20.0, (float) DB::table('exam_questions')->where('exam_id', $examId)->sum('max_score'));
    }

    public function test_reweight_rejects_scores_over_new_max(): void
    {
        $examId = $this->makeExam();
        $this->bulk($examId, [
            ['question_no' => 1, 'max_score' => 10, 'score' => 9],
            ['question_no' => 2, 'max_score' => 10, 'score' => 8],
        ]);
        $ids = DB::table('exam_questions')->where('exam_id', $examId)->orderBy('id')->pluck('id')->all();
        $this->actingAs(User::find(1), 'api')
            ->putJson("/api/v1/exams/{$examId}/question-weights", [
                'weights' => [
                    ['id' => $ids[0], 'max_score' => 15],
                    ['id' => $ids[1], 'max_score' => 5],
                ],
            ])->assertStatus(422);
    }

    public function test_reweight_rejects_wrong_total_and_foreign_rows(): void
    {
        $examId = $this->makeExam();
        $this->bulk($examId, [
            ['question_no' => 1, 'max_score' => 10],
            ['question_no' => 2, 'max_score' => 10],
        ]);
        $q1 = DB::table('exam_questions')->where('exam_id', $examId)->value('id');
        $this->actingAs(User::find(1), 'api')
            ->putJson("/api/v1/exams/{$examId}/question-weights", [
                'weights' => [['id' => $q1, 'max_score' => 5]],
            ])->assertStatus(422);
        $this->actingAs(User::find(1), 'api')
            ->putJson("/api/v1/exams/{$examId}/question-weights", [
                'weights' => [['id' => 1, 'max_score' => 20]],
            ])->assertStatus(422);
    }
}
