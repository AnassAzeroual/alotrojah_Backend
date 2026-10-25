<?php

namespace Tests\Feature;

use App\Models\AcademicSeason;
use App\Models\Group;
use App\Models\Level;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeasonLevelFixtures;
use Tests\TestCase;

/**
 * Levels belong to a center+season. There is no static seed: a first season
 * starts empty and the admin creates its levels; a later season copies the
 * previous season of the same center lane. Clones keep their codes, and the
 * term goal is computed from study weeks only (murajaa/review weeks excluded).
 */
class SeasonLevelsTest extends TestCase
{
    use SeasonLevelFixtures;

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

    private function season(string $name, ?int $centerId = null): AcademicSeason
    {
        return AcademicSeason::create([
            'name' => $name.' '.uniqid(),
            'start_date' => '2026-09-01',
            'total_weeks' => 0,
            'total_sessions' => 0,
            'is_current' => false,
            'center_id' => $centerId,
        ]);
    }

    public function test_a_centers_first_season_starts_empty_and_later_seasons_copy_it(): void
    {
        $first = $this->season('First', 2);
        // No static seed: the first season of a center owns no levels.
        $this->assertSame(0, Level::cloneIntoSeason($first));
        $this->assertSame(0, Level::where('season_id', $first->id)->count());

        // The admin creates the set, then a later season copies it.
        $this->seedSeasonLevels($first);
        Level::where('season_id', $first->id)->where('code', 'L1')->update(['sessions_per_week' => 5]);

        $second = $this->season('Second', 2);
        $this->assertSame(3, Level::cloneIntoSeason($second));
        $rows = Level::where('season_id', $second->id)->orderBy('id')->get();
        $this->assertSame(['L1', 'L2', 'L3'], $rows->pluck('code')->all());
        $this->assertSame([2, 2, 2], $rows->pluck('center_id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(5, (int) $rows->firstWhere('code', 'L1')->sessions_per_week);

        // Idempotent: a season that already owns rows is left untouched.
        $this->assertSame(0, Level::cloneIntoSeason($second));

        // A different center does not inherit another center's set.
        $other = $this->season('Other', 3);
        $this->assertSame(0, Level::cloneIntoSeason($other));
        $this->assertSame(0, Level::where('season_id', $other->id)->count());
    }

    public function test_creating_a_season_through_the_api_copies_the_previous_season(): void
    {
        $this->actingAs(User::find(1), 'api');

        // Center 2 already has an admin-created set in an earlier season.
        $previous = $this->season('Previous', 2);
        $this->seedSeasonLevels($previous);

        $id = $this->postJson('/api/v1/seasons', [
            'name' => 'Clone Season '.uniqid(),
            'start_date' => '2026-09-01',
            'center_id' => 2,
            'terms' => [['name' => 'T1', 'weeks' => 7]],
            'review_weeks_per_term' => 1,
        ])->assertCreated()->json('data.id');

        $rows = Level::where('season_id', $id)->orderBy('id')->get();
        $this->assertSame(['L1', 'L2', 'L3'], $rows->pluck('code')->all());
        $this->assertSame(2, (int) $rows->first()->center_id);

        // A first season (center 3) starts empty.
        $firstId = $this->postJson('/api/v1/seasons', [
            'name' => 'First Season '.uniqid(),
            'start_date' => '2026-09-01',
            'center_id' => 3,
            'terms' => [['name' => 'T1', 'weeks' => 2]],
            'review_weeks_per_term' => 0,
        ])->assertCreated()->json('data.id');
        $this->assertSame(0, Level::where('season_id', $firstId)->count());
    }

    public function test_term_goal_counts_study_weeks_only(): void
    {
        $season = $this->season('Goals');
        $term = $season->terms()->create([
            'term_number' => 1, 'name_ar' => 'T1',
            'start_week' => 1, 'end_week' => 5,
            'start_session_no' => 0, 'end_session_no' => 0,
        ]);
        foreach (['study', 'study', 'study', 'study', 'review'] as $i => $type) {
            $term->weeks()->create([
                'season_id' => $season->id,
                'week_number_global' => $i + 1,
                'week_number_in_term' => $i + 1,
                'week_type' => $type,
                'start_date' => sprintf('2026-09-%02d', $i + 1),
                'end_date' => sprintf('2026-09-%02d', $i + 7),
            ]);
        }
        $this->seedSeasonLevels($season);
        $l1 = Level::where('season_id', $season->id)->where('code', 'L1')->firstOrFail();
        // L1: 3 sessions/week x 1.25 thumn = 3.75 thumn/week.
        $goals = $l1->termGoals($season->terms()->with('weeks')->get());
        $this->assertCount(1, $goals);
        $this->assertSame(4, $goals[0]['study_weeks']);
        $this->assertSame(1, $goals[0]['review_weeks']);
        // 4 study weeks x 3.75 thumn / 8 = 1.875 -> 1.88 (review week excluded).
        $this->assertSame(1.88, $goals[0]['ahzab']);
    }

    public function test_levels_feed_exposes_the_first_term_goal_and_breakdown(): void
    {
        $this->actingAs(User::find(1), 'api');
        $shared = AcademicSeason::findOrFail(1);
        if (Level::forSeason($shared->id)->doesntExist()) {
            $this->seedSeasonLevels($shared);
        }
        // The seeded shared season is 6 terms x 7 weeks (6 study + 1 review).
        $data = $this->getJson('/api/v1/levels?season_id=1')->assertOk()->json('data');
        $l1 = collect($data)->firstWhere('code', 'L1');

        $this->assertCount(6, $l1['term_goals']);
        $this->assertSame(6, $l1['term_goals'][0]['study_weeks']);
        $this->assertSame(1, $l1['term_goals'][0]['review_weeks']);
        // 6 study weeks x 3 sessions x 1.25 thumn / 8 = 2.8125 -> 2.81.
        $this->assertSame(2.81, $l1['ahzab_first_term']);
        $this->assertSame(2.81, $l1['term_goals'][0]['ahzab']);
    }

    public function test_no_scope_never_returns_a_global_level_set(): void
    {
        $this->actingAs(User::find(1), 'api');
        $this->assertSame([], $this->getJson('/api/v1/levels')->assertOk()->json('data'));
        $this->assertSame([], $this->getJson('/api/v1/reference/levels')->assertOk()->json('data'));
    }

    /** A pupil of center 2 sitting on one season's L1 row. */
    private function pupilOn(Level $level, string $email): Student
    {
        $account = User::create([
            'full_name' => 'Rollover Account',
            'email' => $email,
            'password_hash' => 'x',
            'role' => 'student',
            'center_id' => 2,
            'is_active' => true,
        ]);

        return Student::create([
            'user_id' => $account->id,
            'center_id' => 2,
            'group_id' => Group::where('center_id', 2)->value('id'),
            'level_id' => $level->id,
            'full_name' => 'Rollover Pupil',
            'memorization_mode' => 'thumn',
            'status' => 'active',
        ]);
    }

    public function test_activation_carries_the_level_into_the_new_season_and_moves_the_pointer(): void
    {
        $this->actingAs(User::find(1), 'api');

        $first = $this->season('First', 2);
        $this->seedSeasonLevels($first);
        $second = $this->season('Second', 2);
        $this->seedSeasonLevels($second);

        $firstL1 = Level::where('season_id', $first->id)->where('code', 'L1')->firstOrFail();
        $secondL1 = Level::where('season_id', $second->id)->where('code', 'L1')->firstOrFail();
        $this->assertNotSame($firstL1->id, $secondL1->id);
        $pupil = $this->pupilOn($firstL1, 'rollover.'.uniqid().'@example.org');

        $this->postJson("/api/v1/seasons/{$first->id}/activate")->assertOk();
        $this->assertSame($firstL1->id, (int) Student::find($pupil->id)->level_id);
        $this->assertDatabaseHas('student_levels', [
            'student_id' => $pupil->id, 'season_id' => $first->id, 'level_id' => $firstL1->id,
        ]);

        // Season rollover: same code, new season's clone, pointer follows.
        $this->postJson("/api/v1/seasons/{$second->id}/activate")->assertOk();
        $this->assertSame($secondL1->id, (int) Student::find($pupil->id)->level_id);
        $this->assertDatabaseHas('student_levels', [
            'student_id' => $pupil->id, 'season_id' => $second->id, 'level_id' => $secondL1->id,
        ]);
        // History is kept, not moved.
        $this->assertDatabaseHas('student_levels', [
            'student_id' => $pupil->id, 'season_id' => $first->id, 'level_id' => $firstL1->id,
        ]);
    }

    public function test_deleting_a_season_refuses_a_pupil_pointer_without_a_placement(): void
    {
        $this->actingAs(User::find(1), 'api');
        $season = $this->season('Pointer Only', 2);
        $this->seedSeasonLevels($season);
        $l1 = Level::where('season_id', $season->id)->where('code', 'L1')->firstOrFail();
        // A stale pointer with no student_levels row must not reach the FK
        // cascade (a raw 500); it is a recorded fact like a placement.
        $this->pupilOn($l1, 'pointer.'.uniqid().'@example.org');

        $this->deleteJson("/api/v1/seasons/{$season->id}")
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'SEASON_HAS_FACTS');
    }

    public function test_a_shared_season_never_repoints_a_center_with_its_own_current_season(): void
    {
        $this->actingAs(User::find(1), 'api');

        $own = $this->season('Own', 2);
        $this->seedSeasonLevels($own);
        $ownL1 = Level::where('season_id', $own->id)->where('code', 'L1')->firstOrFail();

        $shared = $this->season('Shared', null);
        $this->seedSeasonLevels($shared);

        $pupil = $this->pupilOn($ownL1, 'shared.'.uniqid().'@example.org');
        $this->postJson("/api/v1/seasons/{$own->id}/activate")->assertOk();

        // Activating the legacy shared lane must not steal center 2's pupils.
        $this->postJson("/api/v1/seasons/{$shared->id}/activate")->assertOk();
        $this->assertSame($ownL1->id, (int) Student::find($pupil->id)->level_id);
        $this->assertDatabaseMissing('student_levels', [
            'student_id' => $pupil->id, 'season_id' => $shared->id,
        ]);
    }
}
