<?php

namespace Tests\Feature;

use App\Models\Level;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Own-center writes: supervisors/teachers can never write another center's
 * records. Admin stays global. Transaction-wrapped (seed untouched).
 */
class CrossCenterWritesTest extends TestCase
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

    public function test_supervisor_cannot_write_other_centers(): void
    {
        // Seed fixtures: supervisor 2 + pupil 4 + group 3 live in center 2,
        // pupil 1 + group 1 live in center 1.
        $this->actingAs(User::find(2), 'api'); // supervisor, center 1
        $this->putJson('/api/v1/students/4', ['full_name' => 'Hijacked'])->assertForbidden();
        $this->putJson('/api/v1/groups/3', ['name' => 'Hijacked'])->assertForbidden();

        $this->actingAs(User::find(1), 'api');
        $pupil = $this->postJson('/api/v1/students', [
            'full_name' => 'C2 Plan Pupil', 'memorization_mode' => 'thumn',
            'status' => 'active', 'center_id' => 2, 'group_id' => 3,
            'level_id' => Level::where('code', 'L1')->firstOrFail()->id,
        ])->assertCreated()->json('data.id');
        $plan = $this->putJson('/api/v1/term-plans', [
            'student_id' => $pupil, 'term_id' => 1, 'plan_mode' => 'thumn',
        ])->assertCreated()->json('data.id');

        $this->actingAs(User::find(2), 'api'); // supervisor, center 1
        $this->deleteJson("/api/v1/term-plans/{$plan}")->assertForbidden();
    }

    public function test_supervisor_cannot_touch_other_centers_exams_and_reviews(): void
    {
        $this->actingAs(User::find(1), 'api');
        $pupil = $this->postJson('/api/v1/students', [
            'full_name' => 'C2 Pupil', 'memorization_mode' => 'thumn',
            'status' => 'active', 'center_id' => 2, 'group_id' => 3,
            'level_id' => Level::where('code', 'L1')->firstOrFail()->id,
        ])->assertCreated()->json('data.id');
        $exam = $this->postJson('/api/v1/exams', [
            'student_id' => $pupil, 'exam_type' => 'term_batch', 'term_id' => 1,
            'exam_date' => '2026-10-07',
        ])->assertCreated()->json('data.id');

        $this->actingAs(User::find(2), 'api'); // supervisor, center 1
        $this->patchJson("/api/v1/exams/{$exam}", ['exam_date' => '2026-10-08'])->assertForbidden();
        $this->postJson("/api/v1/exams/{$exam}/questions", ['questions' => [
            ['question_no' => 1, 'max_score' => 20],
        ]])->assertForbidden();
        $this->deleteJson("/api/v1/exams/{$exam}")->assertForbidden();
    }

    public function test_teacher_cannot_write_other_centers_exams(): void
    {
        $this->actingAs(User::find(1), 'api');
        $pupil = $this->postJson('/api/v1/students', [
            'full_name' => 'C2 Pupil 2', 'memorization_mode' => 'thumn',
            'status' => 'active', 'center_id' => 2, 'group_id' => 3,
            'level_id' => Level::where('code', 'L1')->firstOrFail()->id,
        ])->assertCreated()->json('data.id');
        $exam = $this->postJson('/api/v1/exams', [
            'student_id' => $pupil, 'exam_type' => 'term_batch', 'term_id' => 1,
            'exam_date' => '2026-10-07',
        ])->assertCreated()->json('data.id');

        $this->actingAs(User::find(3), 'api'); // teacher, center 1
        $this->patchJson("/api/v1/exams/{$exam}", ['exam_date' => '2026-10-08'])->assertForbidden();
        $this->deleteJson("/api/v1/exams/{$exam}")->assertForbidden();
    }

    public function test_admin_stays_global(): void
    {
        $this->actingAs(User::find(1), 'api');
        $this->putJson('/api/v1/students/4', ['phone' => '0600000000'])->assertOk();
        $this->putJson('/api/v1/groups/3', ['name' => 'Still Center Two'])->assertOk();
    }
}
