<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Teachers read/insert/update but never delete groups, pupils, users
 * (or exams): every destroy path 403s at policy level. Transaction-wrapped.
 */
class TeacherDeleteRefusedTest extends TestCase
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

    public function test_teacher_cannot_delete_group_pupil_user_or_exam(): void
    {
        $this->actingAs(User::find(3), 'api'); // teacher, center 1
        $this->deleteJson('/api/v1/groups/1')->assertForbidden();
        $this->deleteJson('/api/v1/students/1')->assertForbidden();
        $this->deleteJson('/api/v1/users/4')->assertForbidden();

        $examId = DB::table('exams')->value('id');
        $this->assertNotNull($examId);
        $this->deleteJson("/api/v1/exams/{$examId}")->assertForbidden();
    }
}
