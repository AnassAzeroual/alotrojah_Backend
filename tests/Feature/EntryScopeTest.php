<?php

namespace Tests\Feature;

use App\Models\AcademicSeason;
use App\Models\Session;
use App\Models\Term;
use App\Models\User;
use App\Models\Week;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Entry page center-scoping (2026-10-17): the picked group anchors the
 * working center — weeks narrow to that center's current-season lane (own
 * current season, else the legacy shared one) and sessions narrow to the
 * group, refusing cross-center groups for non-admins. No-param reads behave
 * exactly as before.
 */
class EntryScopeTest extends TestCase
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

    public function test_weeks_center_id_prefers_the_centers_own_current_season(): void
    {
        $this->actingAs(User::find(1), 'api');
        $season = AcademicSeason::create([
            'name' => 'Center scope probe', 'hijri_year' => '1447',
            'start_date' => '2026-09-01', 'end_date' => '2027-06-30',
            'total_weeks' => 1, 'total_sessions' => 1,
            'is_current' => true, 'center_id' => 1,
        ]);
        $term = Term::create([
            'season_id' => $season->id, 'term_number' => 1, 'name_ar' => 'Probe term',
            'start_week' => 1, 'end_week' => 1, 'start_session_no' => 1, 'end_session_no' => 1,
        ]);
        Week::create([
            'season_id' => $season->id, 'term_id' => $term->id,
            'week_number_global' => 1, 'week_number_in_term' => 1, 'week_type' => 'study',
            'start_date' => '2026-09-01', 'end_date' => '2026-09-07',
        ]);

        $rows = $this->getJson('/api/v1/weeks?center_id=1')->assertOk()->json('data.data');
        $this->assertCount(1, $rows);
        $this->assertSame($season->id, $rows[0]['season_id']);
        $this->assertSame('Probe term', $rows[0]['term_name']);
        $this->assertSame('Center scope probe', $rows[0]['season_name']);
    }

    public function test_weeks_center_id_falls_back_to_the_shared_current_season(): void
    {
        $this->actingAs(User::find(1), 'api');
        DB::table('academic_seasons')->whereNotNull('center_id')->update(['is_current' => false]);
        $shared = AcademicSeason::where('is_current', true)->whereNull('center_id')->firstOrFail();

        $rows = $this->getJson('/api/v1/weeks?center_id=1')->assertOk()->json('data.data');
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame($shared->id, $row['season_id']);
        }
        $this->assertCount(Week::where('season_id', $shared->id)->count(), $rows);
    }

    public function test_weeks_center_id_without_any_current_uses_newest_season_with_weeks(): void
    {
        $this->actingAs(User::find(1), 'api');
        DB::table('academic_seasons')->update(['is_current' => false]);
        // Newer weekless season must be skipped for the older one with weeks
        // (live case 2026-10-17: center 2 works an unactivated season).
        AcademicSeason::create([
            'name' => 'Weekless probe', 'hijri_year' => '1447',
            'start_date' => '2026-09-01', 'end_date' => '2027-06-30',
            'total_weeks' => 0, 'total_sessions' => 0,
            'is_current' => false, 'center_id' => 2,
        ]);
        $season = AcademicSeason::create([
            'name' => 'Working probe', 'hijri_year' => '1447',
            'start_date' => '2026-09-01', 'end_date' => '2027-06-30',
            'total_weeks' => 1, 'total_sessions' => 1,
            'is_current' => false, 'center_id' => 2,
        ]);
        $term = Term::create([
            'season_id' => $season->id, 'term_number' => 1, 'name_ar' => 'Probe term',
            'start_week' => 1, 'end_week' => 1, 'start_session_no' => 1, 'end_session_no' => 1,
        ]);
        Week::create([
            'season_id' => $season->id, 'term_id' => $term->id,
            'week_number_global' => 1, 'week_number_in_term' => 1, 'week_type' => 'study',
            'start_date' => '2026-09-01', 'end_date' => '2026-09-07',
        ]);

        $rows = $this->getJson('/api/v1/weeks?center_id=2')->assertOk()->json('data.data');
        $this->assertCount(1, $rows);
        $this->assertSame($season->id, $rows[0]['season_id']);
    }

    public function test_weeks_center_id_without_current_nor_own_seasons_uses_shared(): void
    {
        $this->actingAs(User::find(1), 'api');
        DB::table('academic_seasons')->update(['is_current' => false]);
        $shared = AcademicSeason::whereNull('center_id')->firstOrFail();

        $rows = $this->getJson('/api/v1/weeks?center_id=2')->assertOk()->json('data.data');
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame($shared->id, $row['season_id']);
        }
    }

    public function test_weeks_and_sessions_without_params_behave_as_before(): void
    {
        $this->actingAs(User::find(1), 'api');
        $weeks = $this->getJson('/api/v1/weeks')->assertOk()->json('data');
        $this->assertSame(Week::count(), $weeks['meta']['total']);
        $sessions = $this->getJson('/api/v1/sessions-cal')->assertOk()->json('data');
        $this->assertSame(Session::count(), $sessions['meta']['total']);
    }

    public function test_sessions_group_id_narrows_to_the_group(): void
    {
        $this->actingAs(User::find(1), 'api');
        $rows = $this->getJson('/api/v1/sessions-cal?group_id=1')->assertOk()->json('data.data');
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame(1, $row['group_id']);
        }
        $this->assertCount(Session::where('group_id', 1)->count(), $rows);
    }

    public function test_sessions_group_id_refuses_cross_center_groups_for_non_admins(): void
    {
        // Group 3 lives in center 2; the caller teaches in center 1.
        $this->actingAs(User::find(3), 'api');
        $this->getJson('/api/v1/sessions-cal?group_id=3')->assertForbidden()
            ->assertJsonPath('message', 'Group belongs to another center.')
            ->assertJsonPath('errors.code', 'CROSS_CENTER');

        // Own-center group reads fine.
        $this->getJson('/api/v1/sessions-cal?group_id=1')->assertOk();

        // Admins read across centers.
        $this->actingAs(User::find(1), 'api');
        $this->getJson('/api/v1/sessions-cal?group_id=3')->assertOk();
    }

    public function test_sessions_unknown_group_404s(): void
    {
        $this->actingAs(User::find(1), 'api');
        $this->getJson('/api/v1/sessions-cal?group_id=999999')->assertNotFound();
    }
}
