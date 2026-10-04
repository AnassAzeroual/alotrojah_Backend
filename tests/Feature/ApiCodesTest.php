<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Item 7: every 4xx carries a stable `errors.code` (NEED_REPLACER pattern). */
class ApiCodesTest extends TestCase
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

    public function test_admin_only_violation_carries_code(): void
    {
        $this->actingAs(User::find(2), 'api')
            ->postJson('/api/v1/users', [
                'full_name' => 'X', 'email' => 'codes1@example.org',
                'password' => 'password123', 'role' => 'admin',
            ])
            ->assertForbidden()
            ->assertJsonPath('errors.code', 'ADMIN_ONLY');
    }

    public function test_cross_center_violation_carries_code(): void
    {
        $this->actingAs(User::find(3), 'api') // teacher, center 1
            ->postJson('/api/v1/exams', [
                'student_id' => 4, 'exam_type' => 'term_batch', 'term_id' => 1, // center 2
            ])
            ->assertForbidden()
            ->assertJsonPath('errors.code', 'CROSS_CENTER');
    }

    public function test_season_with_facts_carries_code(): void
    {
        $this->actingAs(User::find(1), 'api')
            ->deleteJson('/api/v1/seasons/1')
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'SEASON_HAS_FACTS');
    }

    public function test_weights_total_violation_carries_code(): void
    {
        $this->actingAs(User::find(1), 'api');
        $examId = $this->postJson('/api/v1/exams', [
            'student_id' => 1, 'exam_type' => 'term_batch', 'term_id' => 1,
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/exams/{$examId}/questions", ['questions' => [
            ['question_no' => 1, 'max_score' => 10],
        ]])
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'WEIGHTS_TOTAL');
    }
}
