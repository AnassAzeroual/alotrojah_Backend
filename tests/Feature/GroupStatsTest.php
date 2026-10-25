<?php

namespace Tests\Feature;

use App\Models\AcademicSeason;
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

    public function test_stats_and_detail_follow_the_selected_center_current_season(): void
    {
        // A center can own a current season while the legacy shared season
        // stays current: metrics must use the center lane, not the first row.
        $own = AcademicSeason::create([
            'name' => 'center-lane-current', 'center_id' => 1, 'is_current' => true,
            'start_date' => '2025-01-01', 'end_date' => '2025-12-31',
            'total_weeks' => 42, 'total_sessions' => 126,
        ]);
        $admin = User::find(1);
        $teacher = User::find(3);

        $adminStats = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/groups/stats?center_id=1')->assertOk();
        $this->assertSame($own->id, $adminStats->json('data.season.id'));

        $teacherStats = $this->actingAs($teacher, 'api')
            ->getJson('/api/v1/groups/stats')->assertOk();
        $this->assertSame($own->id, $teacherStats->json('data.season.id'));

        // Unfiltered admin stays on the global view (shared season id 1).
        $globalStats = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/groups/stats')->assertOk();
        $this->assertSame(1, $globalStats->json('data.season.id'));

        $gid = (int) DB::table('groups')->where('center_id', 1)->value('id');
        $detail = $this->actingAs($admin, 'api')
            ->getJson("/api/v1/groups/$gid/detail")->assertOk();
        $this->assertSame('center-lane-current', $detail->json('data.season_name'));
    }

    public function test_detail_of_other_center_group_is_denied(): void
    {
        $gid = (int) DB::table('students')->where('id', 4)->value('group_id'); // center 2
        $this->actingAs(User::find(3), 'api')->getJson("/api/v1/groups/$gid/detail")->assertForbidden();
    }

    public function test_student_role_sees_only_own_group_feeds(): void
    {
        $u = User::create([
            'full_name' => 'S', 'email' => 'gs@example.org', 'password_hash' => 'x',
            'role' => 'student', 'center_id' => 1,
        ]);
        DB::table('students')->where('id', 1)->update(['user_id' => $u->id]);
        $ownGroup = (int) DB::table('students')->where('id', 1)->value('group_id');

        $this->actingAs($u, 'api');
        // stats overview narrows to the pupil's own group
        $r = $this->getJson('/api/v1/groups/stats')->assertOk();
        $this->assertEquals(
            [$ownGroup],
            collect($r->json('data.groups'))->pluck('id')->map(fn ($v) => (int) $v)->all()
        );
        // detail opens for the own group, refuses foreign ones
        $this->getJson("/api/v1/groups/{$ownGroup}/detail")->assertOk();
        $this->getJson('/api/v1/groups/3')->assertForbidden();
    }
}
