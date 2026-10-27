<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Level;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Per-group sessions (000020): every active group owns its session set on
 * its own weekdays, 21:00-23:00 default, UNIQUE(season, group, number).
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

        // 2 weeks x 3 days for A, 2 x 1 for B (21:00-23:00 default slot).
        $this->assertSame(6, DB::table('sessions')->where('season_id', $id)->where('group_id', $a->id)->count());
        $this->assertSame(2, DB::table('sessions')->where('season_id', $id)->where('group_id', $b->id)->count());
        $this->assertSame(0, DB::table('sessions')->where('season_id', $id)->whereNull('group_id')->count());
        $row = DB::table('sessions')->where('season_id', $id)->where('group_id', $a->id)->orderBy('id')->first();
        $this->assertSame('21:00:00', substr((string) $row->start_time, 0, 8));
        $this->assertSame('23:00:00', substr((string) $row->end_time, 0, 8));
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
            'teacher_id' => $t->id, 'schedule_days' => ['Mon'],
        ])->assertCreated()->json('data.id');

        $this->assertSame(1, DB::table('sessions')->where('season_id', $season)->where('group_id', $gid)->count());
        // Late/reactivated groups inherit the same generated template slot.
        $backfilled = DB::table('sessions')->where('season_id', $season)->where('group_id', $gid)->first();
        $this->assertSame('21:00:00', substr((string) $backfilled->start_time, 0, 8));
        $this->assertSame('23:00:00', substr((string) $backfilled->end_time, 0, 8));
    }

    public function test_group_days_rule_the_week_with_no_season_cap(): void
    {
        // Owner-directed 2026-10-15: the season no longer caps days — a group
        // meeting 5 days gets 5 sessions in a single week.
        $t = $this->makeTeacher('five-t@example.org');
        $g = $this->makeGroup('Five Days', $t->id, 1, ['Mon', 'Tue', 'Wed', 'Thu', 'Fri']);

        $this->actingAs(User::find(1), 'api');
        $id = $this->postJson('/api/v1/seasons', [
            'name' => 'Uncapped Season', 'start_date' => '2026-09-07', 'center_id' => 1,
            'terms' => [['name' => 'T1', 'weeks' => 1]],
        ])->assertCreated()->json('data.id');

        $this->assertSame(5, DB::table('sessions')->where('season_id', $id)->where('group_id', $g->id)->count());
    }

    public function test_day_edit_rebuilds_future_factless_sessions_only(): void
    {
        // Owner-directed 2026-10-15: changing weekdays rebuilds the future —
        // fact-less planned rows move to the new days, past + recorded stay.
        // Frozen clock: the 8-week season ends 2026-11-01, so after the last
        // Monday (2026-10-26) passes there is no future Monday left to score.
        $this->travelTo(Carbon::create(2026, 10, 15));
        $t = $this->makeTeacher('rebuild-t@example.org');
        $g = $this->makeGroup('Rebuild Me', $t->id, 1, ['Mon', 'Wed']);

        $this->actingAs(User::find(1), 'api');
        $sid = $this->postJson('/api/v1/seasons', [
            'name' => 'Rebuild Season', 'start_date' => '2026-09-07', 'center_id' => 1,
            'terms' => [['name' => 'T1', 'weeks' => 8]],
        ])->assertCreated()->json('data.id');

        $today = now()->toDateString();
        $this->postJson("/api/v1/seasons/{$sid}/activate")->assertOk();
        $this->assertGreaterThan(0, DB::table('sessions')->where('season_id', $sid)
            ->where('group_id', $g->id)->where('planned_date', '<', $today)->count());

        // One recorded fact on a FUTURE Monday: scores anchor the row.
        $acct = User::create([
            'full_name' => 'Rebuild Pupil Acct', 'email' => 'rebuild-pupil@example.org',
            'password_hash' => 'x', 'role' => 'student', 'center_id' => 1, 'is_active' => true,
        ]);
        $pupil = $this->postJson('/api/v1/students', [
            'full_name' => 'Rebuild Pupil', 'memorization_mode' => 'thumn',
            'status' => 'active', 'center_id' => 1, 'group_id' => $g->id,
            'level_id' => Level::where('code', 'L1')->firstOrFail()->id,
            'user_id' => $acct->id,
        ])->assertCreated()->json('data.id');
        $scoredId = DB::table('sessions')->where('season_id', $sid)->where('group_id', $g->id)
            ->where('planned_date', '>=', $today)->orderBy('planned_date')->pluck('id', 'planned_date')
            ->first(fn ($id, $date) => (int) date('N', strtotime($date)) === 1);
        $this->assertNotNull($scoredId);
        $this->actingAs(User::find(3), 'api');
        $this->postJson('/api/v1/scores/bulk', [
            'session_id' => $scoredId,
            'records' => [['student_id' => $pupil, 'module_code' => 'hifz', 'score' => 10]],
        ])->assertCreated();

        // Change the days: Tue/Thu from here on.
        $this->actingAs(User::find(1), 'api');
        $this->putJson("/api/v1/groups/{$g->id}", ['schedule_days' => ['Tue', 'Thu']])->assertOk();

        $rows = DB::table('sessions')->where('season_id', $sid)->where('group_id', $g->id)->get();
        foreach ($rows as $r) {
            $dow = (int) date('N', strtotime($r->planned_date));
            if ($r->planned_date < $today) {
                // Past untouched: still Mon/Wed.
                $this->assertContains($dow, [1, 3]);
            } elseif ((int) $r->id === (int) $scoredId) {
                // Recorded future Monday survives on its old date.
                $this->assertSame(1, $dow);
            } else {
                // Rebuilt future: only the new days.
                $this->assertContains($dow, [2, 4]);
            }
        }
        $this->assertNotNull(DB::table('sessions')->where('id', $scoredId)->first());
        // Counter recounted to match.
        $this->assertSame(
            DB::table('sessions')->where('season_id', $sid)->count(),
            DB::table('academic_seasons')->where('id', $sid)->value('total_sessions')
        );
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
