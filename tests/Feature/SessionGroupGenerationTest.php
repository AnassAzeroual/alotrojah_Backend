<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Level;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Per-group sessions (000020): every active group owns its session set on
 * its own weekdays, 08:00-09:00 default, UNIQUE(season, group, number).
 * Transaction-wrapped (seed untouched).
 */
class SessionGroupGenerationTest extends TestCase
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

    private function makeTeacher(string $email, int $center = 1): User
    {
        return User::create([
            'full_name' => "Gen {$email}", 'email' => $email,
            'password_hash' => Hash::make('password123'), 'role' => 'teacher',
            'center_id' => $center, 'teacher_type' => 'hifz', 'is_active' => true,
        ]);
    }

    private function makeGroup(string $name, int $teacherId, int $center, array $days): Group
    {
        $g = Group::create([
            'name' => $name, 'center_id' => $center,
            'level_id' => Level::where('code', 'L1')->firstOrFail()->id,
            'teacher_id' => $teacherId, 'is_active' => true,
        ]);
        $g->weekdays()->createMany(array_map(fn ($d) => ['weekday' => $d], $days));

        return $g;
    }

    public function test_season_generates_one_set_per_group_on_its_weekdays(): void
    {
        $t = $this->makeTeacher('gen-t@example.org');
        $a = $this->makeGroup('Gen A', $t->id, 1, ['Mon', 'Wed', 'Fri']);
        $b = $this->makeGroup('Gen B', $t->id, 1, ['Tue']);

        $this->actingAs(User::find(1), 'api');
        $id = $this->postJson('/api/v1/seasons', [
            'name' => 'Gen Season', 'start_date' => '2026-09-07', 'center_id' => 1,
            'terms' => [['name' => 'T1', 'weeks' => 2]],
        ])->assertCreated()->json('data.id');

        // 2 weeks x 3 days for A, 2 x 1 for B (08:00 default slot).
        $this->assertSame(6, DB::table('sessions')->where('season_id', $id)->where('group_id', $a->id)->count());
        $this->assertSame(2, DB::table('sessions')->where('season_id', $id)->where('group_id', $b->id)->count());
        $this->assertSame(0, DB::table('sessions')->where('season_id', $id)->whereNull('group_id')->count());
        $row = DB::table('sessions')->where('season_id', $id)->where('group_id', $a->id)->orderBy('id')->first();
        $this->assertSame('08:00:00', substr((string) $row->start_time, 0, 8));
        $this->assertSame('09:00:00', substr((string) $row->end_time, 0, 8));
        // Dates land on the group's own weekdays (Mon 2026-09-07 start).
        $wds = DB::table('sessions')->where('season_id', $id)->where('group_id', $a->id)
            ->pluck('planned_date')->map(fn ($d) => date('D', strtotime($d)))->unique()->values()->all();
        sort($wds);
        $order = ['Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6, 'Sun' => 7];
        usort($wds, fn ($a, $b) => $order[$a] <=> $order[$b]);
        $this->assertSame(['Mon', 'Wed', 'Fri'], $wds);
        // The counter covers every active group of the center, not just ours.
        $this->assertSame(
            DB::table('sessions')->where('season_id', $id)->count(),
            DB::table('academic_seasons')->where('id', $id)->value('total_sessions')
        );
    }

    public function test_group_store_backfills_open_seasons(): void
    {
        $this->actingAs(User::find(1), 'api');
        $season = $this->postJson('/api/v1/seasons', [
            'name' => 'Backfill Season', 'start_date' => '2026-09-07', 'center_id' => 1,
            'terms' => [['name' => 'T1', 'weeks' => 1]],
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/seasons/{$season}/activate")->assertOk();

        $t = $this->makeTeacher('late-t@example.org');
        $gid = $this->postJson('/api/v1/groups', [
            'name' => 'Late Group', 'center_id' => 1,
            'level_id' => Level::where('code', 'L1')->firstOrFail()->id,
            'teacher_id' => $t->id, 'schedule_days' => ['Mon'],
        ])->assertCreated()->json('data.id');

        $this->assertSame(1, DB::table('sessions')->where('season_id', $season)->where('group_id', $gid)->count());
    }

    public function test_session_time_order_is_refused_with_code(): void
    {
        $sid = DB::table('sessions')->where('group_id', 1)->orderBy('id')->value('id');
        $this->assertNotNull($sid);
        $this->actingAs(User::find(1), 'api');
        $this->patchJson("/api/v1/sessions-cal/{$sid}", ['start_time' => '09:00', 'end_time' => '08:00'])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'SESSION_TIME_ORDER');
        $this->patchJson("/api/v1/sessions-cal/{$sid}", ['start_time' => '10:00', 'end_time' => '11:00'])
            ->assertOk();
        $this->assertSame('10:00:00', DB::table('sessions')->where('id', $sid)->value('start_time'));
    }
}
