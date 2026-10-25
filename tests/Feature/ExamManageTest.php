<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** PATCH /exams/{id} (date/type) + DELETE (questions cascade). Transaction-wrapped. */
class ExamManageTest extends TestCase
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

    public function test_admin_updates_date_and_type_to_final(): void
    {
        $this->actingAs(User::find(1), 'api');
        $this->patchJson('/api/v1/exams/1', [
            'exam_date' => '2026-10-07', 'exam_type' => 'final_season',
        ])->assertOk()
            ->assertJsonPath('data.exam_date', '2026-10-07')
            ->assertJsonPath('data.exam_type', 'final_season')
            ->assertJsonPath('data.term_id', null);
    }

    public function test_type_change_to_term_batch_requires_term(): void
    {
        $this->actingAs(User::find(1), 'api');
        // exam 1 is term-linked in seed; force final first, then back without a term
        $this->patchJson('/api/v1/exams/1', ['exam_type' => 'final_season'])->assertOk();
        $this->patchJson('/api/v1/exams/1', ['exam_type' => 'term_batch'])
            ->assertStatus(422);
        $this->patchJson('/api/v1/exams/1', ['exam_type' => 'term_batch', 'term_id' => 2])
            ->assertOk()
            ->assertJsonPath('data.term_id', 2)
            ->assertJsonPath('data.season_id', 1);
    }

    public function test_admin_creates_weekly_exam_without_term(): void
    {
        $this->actingAs(User::find(1), 'api');
        $this->postJson('/api/v1/exams', [
            'student_id' => 1, 'exam_type' => 'weekly', 'season_id' => 1,
            'exam_date' => '2026-10-15',
        ])->assertCreated()
            ->assertJsonPath('data.exam_type', 'weekly')
            ->assertJsonPath('data.term_id', null)
            ->assertJsonPath('data.season_id', 1);
    }

    public function test_weekly_exam_requires_season(): void
    {
        $this->actingAs(User::find(1), 'api');
        $this->postJson('/api/v1/exams', [
            'student_id' => 1, 'exam_type' => 'weekly', 'exam_date' => '2026-10-15',
        ])->assertUnprocessable();
    }

    public function test_type_change_to_weekly_clears_term(): void
    {
        $this->actingAs(User::find(1), 'api');
        $this->patchJson('/api/v1/exams/1', ['exam_type' => 'weekly', 'season_id' => 1])
            ->assertOk()
            ->assertJsonPath('data.exam_type', 'weekly')
            ->assertJsonPath('data.term_id', null);
    }

    public function test_teacher_can_update_but_not_delete(): void
    {
        $this->actingAs(User::find(3), 'api');
        $this->patchJson('/api/v1/exams/1', ['exam_date' => '2026-10-08'])->assertOk();
        $this->deleteJson('/api/v1/exams/1')->assertForbidden();
    }

    public function test_admin_starts_a_test_with_questions_atomically(): void
    {
        $this->actingAs(User::find(1), 'api');
        $before = Exam::where('student_id', 1)->count();
        $response = $this->postJson('/api/v1/exams', [
            'student_id' => 1, 'exam_type' => 'weekly', 'season_id' => 1,
            'exam_date' => '2026-10-25',
            'questions' => [
                ['question_no' => 1, 'max_score' => 10, 'score' => 8, 'prompt_text' => 'Hizb 1'],
                ['question_no' => 2, 'max_score' => 10, 'score' => 9, 'prompt_text' => 'Hizb 2'],
            ],
        ])->assertCreated()
            ->assertJsonCount(2, 'data.questions')
            ->assertJsonPath('data.overall_avg', 17);

        $this->assertSame($before + 1, Exam::where('student_id', 1)->count());
        $this->assertSame(2, DB::table('exam_questions')->where('exam_id', $response->json('data.id'))->count());
    }

    public function test_started_test_rejects_bad_weights_without_creating_an_exam(): void
    {
        $this->actingAs(User::find(1), 'api');
        $before = Exam::where('student_id', 1)->count();
        $this->postJson('/api/v1/exams', [
            'student_id' => 1, 'exam_type' => 'weekly', 'season_id' => 1,
            'questions' => [
                ['question_no' => 1, 'max_score' => 10, 'prompt_text' => 'Hizb 1'],
                ['question_no' => 2, 'max_score' => 5, 'prompt_text' => 'Hizb 2'],
            ],
        ])->assertUnprocessable();
        $this->assertSame($before, Exam::where('student_id', 1)->count());
    }

    public function test_started_test_rejects_a_score_above_its_weight(): void
    {
        $this->actingAs(User::find(1), 'api');
        $this->postJson('/api/v1/exams', [
            'student_id' => 1, 'exam_type' => 'weekly', 'season_id' => 1,
            'questions' => [
                ['question_no' => 1, 'max_score' => 10, 'score' => 11, 'prompt_text' => 'Hizb 1'],
                ['question_no' => 2, 'max_score' => 10, 'score' => 0, 'prompt_text' => 'Hizb 2'],
            ],
        ])->assertUnprocessable();
    }

    public function test_admin_delete_cascades_questions(): void
    {
        $qid = DB::table('exam_questions')->where('exam_id', 1)->value('id');
        $this->assertNotNull($qid);
        $this->actingAs(User::find(1), 'api')
            ->deleteJson('/api/v1/exams/1')->assertOk();
        $this->assertNull(Exam::find(1));
        $this->assertEquals(0, DB::table('exam_questions')->where('exam_id', 1)->count());
    }
}
