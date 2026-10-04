<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Groups overview / detail feeds: center isolation + aggregates (seeded DB, transaction-wrapped). */
class GroupStatsTest extends TestCase
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

    public function test_teacher_stats_are_limited_to_own_center(): void
    {
        $r = $this->actingAs(User::find(3), 'api')->getJson('/api/v1/groups/stats')->assertOk();

        $groups = collect($r->json('data.groups'));
        $this->assertTrue($groups->isNotEmpty());
        $this->assertEquals([1], $groups->pluck('center_id')->unique()->values()->all());
        $this->assertSame(3, $r->json('data.kpis.students')); // seed: 3 students in center 1
        $this->assertSame((int) $groups->sum('students_count'), $r->json('data.kpis.students'));
    }

    public function test_admin_sees_all_centers(): void
    {
        $r = $this->actingAs(User::find(1), 'api')->getJson('/api/v1/groups/stats')->assertOk();

        $this->assertSame(7, $r->json('data.kpis.students'));
        $this->assertGreaterThan(1, collect($r->json('data.groups'))->pluck('center_id')->unique()->count());
    }

    public function test_detail_lists_group_students_and_metrics(): void
    {
        $gid = (int) DB::table('students')->where('id', 1)->value('group_id');
        $r = $this->actingAs(User::find(3), 'api')->getJson("/api/v1/groups/$gid/detail")->assertOk();

        $this->assertContains(1, collect($r->json('data.students'))->pluck('id')->all());
        $this->assertSame($gid, $r->json('data.group.id'));
        $this->assertArrayHasKey('trend', $r->json('data'));
    }

    public function test_detail_of_other_center_group_is_denied(): void
    {
        $gid = (int) DB::table('students')->where('id', 4)->value('group_id'); // center 2
        $this->actingAs(User::find(3), 'api')->getJson("/api/v1/groups/$gid/detail")->assertForbidden();
    }

    public function test_student_role_cannot_use_group_feeds(): void
    {
        $u = User::create([
            'full_name' => 'S', 'email' => 'gs@example.org', 'password_hash' => 'x',
            'role' => 'student', 'center_id' => 1,
        ]);
        $this->actingAs($u, 'api')->getJson('/api/v1/groups/stats')->assertForbidden();
    }
}
