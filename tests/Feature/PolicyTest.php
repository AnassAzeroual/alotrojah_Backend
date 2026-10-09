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
        // count from the shared dev DB rather than hardcoding — the seed drifts
        $expected = DB::table('students')->where('center_id', 1)->count();
        $this->getJson('/api/v1/students')->assertOk()->assertJsonPath('data.meta.total', $expected);
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
        $sid = DB::table('sessions')->where('group_id', 1)->orderBy('id')->value('id');
        $this->assertNotNull($sid);
        $this->actingAs(User::find(19), 'api')
            ->postJson('/api/v1/scores/bulk', [
                'session_id' => $sid,
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
        // Per-group sessions: each center reads its own pupils' rows on any
        // session id — scope follows the pupil, never the session's group.
        $s1 = DB::table('sessions')->where('group_id', 1)->orderBy('id')->value('id');
        $s3 = DB::table('sessions')->where('group_id', 3)->orderBy('id')->value('id');
        $this->assertNotNull($s1);
        $this->assertNotNull($s3);
        $this->actingAs(User::find(1), 'api');
        $this->postJson('/api/v1/scores/bulk', ['session_id' => $s1, 'records' => [
            ['student_id' => 1, 'module_code' => 'hifz', 'score' => 12],
            ['student_id' => 2, 'module_code' => 'hifz', 'score' => 13],
        ]])->assertCreated();
        $this->postJson('/api/v1/scores/bulk', ['session_id' => $s3, 'records' => [
            ['student_id' => 4, 'module_code' => 'hifz', 'score' => 11],
            ['student_id' => 5, 'module_code' => 'hifz', 'score' => 10],
        ]])->assertCreated();

        $this->actingAs(User::find(3), 'api'); // teacher, center 1
        $ids = collect($this->getJson("/api/v1/sessions/{$s1}/scores")->assertOk()->json('data'))
            ->pluck('student_id')->all();
        $this->assertContains(1, $ids);
        $this->assertContains(2, $ids);
        $this->assertNotContains(4, $ids);
        $this->assertNotContains(5, $ids);

        $this->actingAs(User::find(7), 'api'); // teacher, center 2
        $ids = collect($this->getJson("/api/v1/sessions/{$s3}/scores")->assertOk()->json('data'))
            ->pluck('student_id')->all();
        $this->assertContains(4, $ids);
        $this->assertContains(5, $ids);
        $this->assertNotContains(1, $ids);
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

    public function test_users_index_filters_by_name_query(): void
    {
        $this->actingAs(User::find(1), 'api'); // admin sees all centers
        $r = $this->getJson('/api/v1/users?q=zzz-no-such-name')->assertOk();
        $this->assertEquals(0, $r->json('data.meta.total'));
        $known = User::find(3)->full_name;
        $r = $this->getJson('/api/v1/users?q='.urlencode(mb_substr($known, 0, 4)))->assertOk();
        $this->assertGreaterThanOrEqual(1, $r->json('data.meta.total'));
    }

    public function test_supervisor_creates_group_forced_to_own_center(): void
    {
        $r = $this->actingAs(User::find(2), 'api') // supervisor, center 1
            ->postJson('/api/v1/groups', [
                'name' => 'Policy Test Group', 'center_id' => 2, 'level_id' => 1, 'teacher_id' => 3, // tries center 2
            ])->assertStatus(201);
        $this->assertEquals(1, $r->json('data.center_id'));
        $this->assertDatabaseHas('groups', ['name' => 'Policy Test Group', 'center_id' => 1]);
    }

    public function test_teacher_cannot_create_group(): void
    {
        $this->actingAs(User::find(3), 'api')
            ->postJson('/api/v1/groups', ['name' => 'X', 'center_id' => 1, 'level_id' => 1])
            ->assertForbidden();
    }

    public function test_admin_cannot_assign_other_center_teacher_to_new_group(): void
    {
        $this->actingAs(User::find(1), 'api') // admin, center NULL
            ->postJson('/api/v1/groups', [
                'name' => 'Bad Teacher Group', 'center_id' => 1, 'level_id' => 1, 'teacher_id' => 7, // teacher of center 2
            ])->assertStatus(422);
    }

    public function test_student_calendar_reads_own_group_only(): void
    {
        $u = User::create([
            'full_name' => 'Student Cal', 'email' => 'cal@example.org',
            'password_hash' => Hash::make('password123'), 'role' => 'student', 'center_id' => 1,
        ]);
        DB::table('students')->where('id', 1)->update(['user_id' => $u->id]);
        $ownGroup = (int) DB::table('students')->where('id', 1)->value('group_id');
        $this->assertSame(1, $ownGroup);
        $this->actingAs($u, 'api');

        // seasons read OK, center-scoped (shared legacy + own center only)
        $seasons = $this->getJson('/api/v1/seasons')->assertOk()->json('data.data');
        $this->assertNotEmpty($seasons);
        foreach ($seasons as $s) {
            $this->assertTrue($s['center_id'] === null || (int) $s['center_id'] === 1);
        }

        // groups index narrows to the pupil's own group
        $groups = $this->getJson('/api/v1/groups')->assertOk()->json('data.data');
        $this->assertEquals([$ownGroup], collect($groups)->pluck('id')->map(fn ($v) => (int) $v)->all());

        // other groups refused, own group opens
        $this->getJson('/api/v1/groups/3')->assertForbidden();
        $this->getJson("/api/v1/groups/{$ownGroup}")->assertOk();

        // every term detail exposes only own-group sessions (or none)
        $found = 0;
        foreach ($seasons as $s) {
            $terms = $this->getJson("/api/v1/seasons/{$s['id']}/terms")->assertOk()->json('data');
            foreach ($terms as $t) {
                $detail = $this->getJson("/api/v1/terms/{$t['id']}")->assertOk()->json('data');
                foreach ($detail['weeks'] ?? [] as $w) {
                    foreach ($w['sessions'] ?? [] as $sess) {
                        $this->assertSame($ownGroup, (int) $sess['group_id']);
                        $found++;
                    }
                }
            }
        }
        $this->assertGreaterThan(0, $found);

        // calendar writes stay manager-only
        $sid = DB::table('sessions')->where('group_id', $ownGroup)->orderBy('id')->value('id');
        $this->assertNotNull($sid);
        $this->patchJson("/api/v1/sessions-cal/{$sid}", ['planned_date' => '2026-11-01'])
            ->assertForbidden();
    }

    public function test_teacher_calendar_reads_without_writes(): void
    {
        $this->actingAs(User::find(3), 'api'); // teacher, center 1
        $this->getJson('/api/v1/seasons')->assertOk();
        $this->getJson('/api/v1/groups')->assertOk();
        $sid = DB::table('sessions')->where('group_id', 1)->orderBy('id')->value('id');
        $this->assertNotNull($sid);
        $this->patchJson("/api/v1/sessions-cal/{$sid}", ['planned_date' => '2026-11-01'])
            ->assertForbidden();
    }

    public function test_session_times_cap_at_22_00(): void
    {
        $sid = DB::table('sessions')->where('group_id', 1)->orderBy('id')->value('id');
        $this->assertNotNull($sid);
        $this->actingAs(User::find(1), 'api'); // admin clears every other gate
        $this->patchJson("/api/v1/sessions-cal/{$sid}", ['start_time' => '21:00', 'end_time' => '23:00'])
            ->assertStatus(422);
        $this->patchJson("/api/v1/sessions-cal/{$sid}", ['start_time' => '21:00', 'end_time' => '22:00'])
            ->assertOk();
    }
}
