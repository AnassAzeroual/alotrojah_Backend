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

    public function test_teacher_can_update_but_not_delete(): void
    {
        $this->actingAs(User::find(3), 'api');
        $this->patchJson('/api/v1/exams/1', ['exam_date' => '2026-10-08'])->assertOk();
        $this->deleteJson('/api/v1/exams/1')->assertForbidden();
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
