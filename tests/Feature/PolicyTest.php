<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * S13: center isolation + role gates. Transaction-wrapped (seed untouched).
 * NOTE: actingAs() is used instead of JWT tokens on purpose — the auth guard
 * caches its user per app instance, so two different tokens in ONE test would
 * silently authenticate as the first user. actingAs sets a fresh user per call.
 */
class PolicyTest extends TestCase
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

    public function test_teacher_is_scoped_to_own_center(): void
    {
        $this->actingAs(User::find(3), 'api');
        $this->getJson('/api/v1/students')->assertOk()->assertJsonPath('data.meta.total', 3);
        // in tests the console skips CenterScope, so policy denies with 403
        // (live HTTP gives 404 via scoped binding — equally safe)
        $this->getJson('/api/v1/students/4')->assertForbidden();
        $this->getJson('/api/v1/students/1')->assertOk();
    }

    public function test_teacher_cannot_create_student_in_other_center(): void
    {
        $this->actingAs(User::find(3), 'api')
            ->postJson('/api/v1/students', [
                'full_name' => 'X', 'memorization_mode' => 'thumn', 'group_id' => 3, // center 2
            ])->assertStatus(422);
    }

    public function test_supervisor_cannot_touch_global_admin(): void
    {
        $this->actingAs(User::find(2), 'api');
        $this->putJson('/api/v1/users/1', ['full_name' => 'Hacked'])->assertForbidden();
        $this->getJson('/api/v1/users/1')->assertForbidden();
    }

    public function test_supervisor_manages_own_center_teacher(): void
    {
        $this->actingAs(User::find(2), 'api')
            ->putJson('/api/v1/users/3', ['phone' => '0600000000'])
            ->assertOk()->assertJsonPath('data.phone', '0600000000');
    }

    public function test_murajaa_teacher_cannot_enter_weekly_scores(): void
    {
        $this->actingAs(User::find(19), 'api')
            ->postJson('/api/v1/scores/bulk', [
                'session_id' => 9,
                'records' => [['student_id' => 1, 'module_code' => 'hifz', 'score' => 10]],
            ])->assertForbidden();
    }

    public function test_teacher_can_conduct_exam_in_own_center(): void
    {
        $this->actingAs(User::find(5), 'api')
            ->postJson('/api/v1/exams', [
                'student_id' => 1, 'exam_type' => 'term_batch', 'term_id' => 1,
            ])->assertCreated()->assertJsonPath('data.examiner_id', 5);
    }

    public function test_student_role_sees_only_self(): void
    {
        $u = User::create([
            'full_name' => 'Student Self', 'email' => 'self@example.org',
            'password_hash' => Hash::make('password123'), 'role' => 'student', 'center_id' => 1,
        ]);
        DB::table('students')->where('id', 1)->update(['user_id' => $u->id]);
        $this->actingAs($u, 'api')->getJson('/api/v1/students')
            ->assertOk()->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.data.0.id', 1);
    }

    public function test_teacher_cannot_use_manager_pages(): void
    {
        $this->actingAs(User::find(3), 'api');
        $this->postJson('/api/v1/seasons', ['name' => 'X', 'start_date' => '2099-01-01'])
            ->assertForbidden();
        $this->putJson('/api/v1/scoring-modules', ['modules' => [['code' => 'hifz', 'max_points' => 14]]])
            ->assertForbidden();
    }

    public function test_by_session_scores_are_center_scoped(): void
    {
        $this->actingAs(User::find(3), 'api'); // teacher, center 1
        $r = $this->getJson('/api/v1/sessions/1/scores')->assertOk();
        $this->assertEquals(
            [1, 2, 3],
            collect($r->json('data'))->pluck('student_id')->sort()->values()->all()
        );

        $this->actingAs(User::find(7), 'api'); // teacher, center 2
        $r = $this->getJson('/api/v1/sessions/1/scores')->assertOk();
        $this->assertEquals([4, 5], collect($r->json('data'))->pluck('student_id')->sort()->values()->all());
    }

    public function test_student_role_sees_only_own_session_scores(): void
    {
        $u = User::create([
            'full_name' => 'Student Sheet', 'email' => 'sheet@example.org',
            'password_hash' => Hash::make('password123'), 'role' => 'student', 'center_id' => 1,
        ]);
        DB::table('students')->where('id', 2)->update(['user_id' => $u->id]);
        $r = $this->actingAs($u, 'api')->getJson('/api/v1/sessions/1/scores')->assertOk();
        $this->assertEquals([2], collect($r->json('data'))->pluck('student_id')->all());
    }
}
