<?php

namespace Tests\Feature;

use App\Models\AcademicSeason;
use App\Models\Center;
use App\Models\Group;
use App\Models\Level;
use App\Models\Student;
use App\Models\StudentLevel;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeasonLevelFixtures;
use Tests\TestCase;

/**
 * Pupil placement is per season. A submitted level (another season's row or
 * the current one) is mapped onto the center's current season by `code` (L1
 * means the same level every season), `student_levels` is upserted, and the
 * legacy `students.level_id` pointer follows the mapped row.
 */
class StudentPlacementTest extends TestCase
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

    private function seasonFor(int $centerId, string $name = 'Placement'): AcademicSeason
    {
        $season = AcademicSeason::create([
            'name' => $name.' '.uniqid(),
            'start_date' => '2027-09-01',
            'total_weeks' => 0,
            'total_sessions' => 0,
            'is_current' => true,
            'center_id' => $centerId,
        ]);
        $this->seedSeasonLevels($season);

        return $season;
    }

    private function seasonLevel(AcademicSeason $season, string $code): Level
    {
        return Level::where('season_id', $season->id)->where('code', $code)->firstOrFail();
    }

    private function account(string $email, int $center = 2): User
    {
        return User::create([
            'full_name' => "Placement {$email}",
            'email' => $email,
            'password_hash' => 'x',
            'role' => 'student',
            'center_id' => $center,
            'is_active' => true,
        ]);
    }

    private function payload(User $account, int $levelId, array $over = []): array
    {
        return array_merge([
            'user_id' => $account->id,
            'full_name' => 'Placed Pupil',
            'center_id' => 2,
            'group_id' => Group::where('center_id', 2)->value('id'),
            'level_id' => $levelId,
            'memorization_mode' => 'thumn',
            'status' => 'active',
        ], $over);
    }

    public function test_store_places_the_pupil_on_the_current_seasons_level(): void
    {
        $this->actingAs(User::find(1), 'api');
        $season = $this->seasonFor(2);
        $l1 = $this->seasonLevel($season, 'L1');

        $studentId = $this->postJson(
            '/api/v1/students',
            $this->payload($this->account('placed-current@example.org'), $l1->id)
        )->assertCreated()
            ->assertJsonPath('data.level_id', $l1->id)
            ->json('data.id');

        $this->assertSame($l1->id, (int) Student::find($studentId)->level_id);
        $this->assertDatabaseHas('student_levels', [
            'student_id' => $studentId,
            'season_id' => $season->id,
            'level_id' => $l1->id,
        ]);
    }

    public function test_a_previous_seasons_level_maps_onto_the_current_seasons_copy_by_code(): void
    {
        $this->actingAs(User::find(1), 'api');
        $first = $this->seasonFor(2, 'Placement First');
        $second = $this->seasonFor(2, 'Placement Second');
        $previousL1 = $this->seasonLevel($first, 'L1');
        $currentL1 = $this->seasonLevel($second, 'L1');
        $this->assertNotSame($previousL1->id, $currentL1->id);

        $studentId = $this->postJson(
            '/api/v1/students',
            $this->payload($this->account('placed-previous@example.org'), $previousL1->id)
        )->assertCreated()
            ->assertJsonPath('data.level_id', $currentL1->id)
            ->json('data.id');

        $this->assertDatabaseHas('student_levels', [
            'student_id' => $studentId,
            'season_id' => $second->id,
            'level_id' => $currentL1->id,
        ]);
        $this->assertDatabaseMissing('student_levels', [
            'student_id' => $studentId,
            'season_id' => $first->id,
        ]);
    }

    public function test_update_re_places_the_pupil_without_duplicating_the_season_row(): void
    {
        $this->actingAs(User::find(1), 'api');
        $season = $this->seasonFor(2);
        $studentId = $this->postJson(
            '/api/v1/students',
            $this->payload($this->account('placed-update@example.org'), $this->seasonLevel($season, 'L1')->id)
        )->assertCreated()->json('data.id');

        $l2 = $this->seasonLevel($season, 'L2');
        $this->putJson("/api/v1/students/{$studentId}", ['level_id' => $l2->id])
            ->assertOk()->assertJsonPath('data.level_id', $l2->id);

        $this->assertSame($l2->id, (int) Student::find($studentId)->level_id);
        $this->assertSame(1, StudentLevel::where('student_id', $studentId)->count());
        $this->assertDatabaseHas('student_levels', [
            'student_id' => $studentId,
            'season_id' => $season->id,
            'level_id' => $l2->id,
        ]);
    }

    public function test_a_center_without_any_season_keeps_the_raw_level_id(): void
    {
        // Park the legacy shared lane on another center so center 2 resolves to
        // no season at all: nothing exists to place against.
        $other = Center::create(['name' => 'Placement Seasonless']);
        DB::table('academic_seasons')->whereNull('center_id')->update(['center_id' => $other->id]);
        $this->assertNull(AcademicSeason::currentFor(2));

        $this->actingAs(User::find(1), 'api');
        $foreignLevel = $this->seasonLevel($this->seasonFor(3, 'Placement Other Center'), 'L1');
        $studentId = $this->postJson(
            '/api/v1/students',
            $this->payload($this->account('placed-seasonless@example.org'), $foreignLevel->id)
        )->assertCreated()
            ->assertJsonPath('data.level_id', $foreignLevel->id)
            ->json('data.id');

        $this->assertSame($foreignLevel->id, (int) Student::find($studentId)->level_id);
        $this->assertDatabaseMissing('student_levels', ['student_id' => $studentId]);
    }

    public function test_the_list_embeds_the_season_level_and_filters_by_code(): void
    {
        $this->actingAs(User::find(1), 'api');
        $previous = $this->seasonFor(2, 'Placement Previous');
        $season = $this->seasonFor(2, 'Placement Current');
        $studentId = $this->postJson(
            '/api/v1/students',
            $this->payload($this->account('listed-'.uniqid().'@example.org'), $this->seasonLevel($season, 'L1')->id)
        )->assertCreated()->json('data.id');
        $clone = $this->seasonLevel($season, 'L1');

        // The row carries the season-owned level, not just an id.
        $row = collect(
            $this->getJson('/api/v1/students?q='.urlencode('Placed Pupil'))->assertOk()->json('data.data')
        )->firstWhere('id', $studentId);
        $this->assertNotNull($row);
        $this->assertSame($clone->id, $row['level_id']);
        $this->assertSame('L1', $row['level']['code']);
        $this->assertSame($clone->name_ar, $row['level']['name_ar']);

        // Filtering by the PREVIOUS season's L1 still matches by code.
        $hit = $this->getJson('/api/v1/students?level_id='.$this->seasonLevel($previous, 'L1')->id)
            ->assertOk()->json('data.data');
        $this->assertContains($studentId, array_column($hit, 'id'));

        $miss = $this->getJson('/api/v1/students?level_id='.$this->seasonLevel($season, 'L2')->id)
            ->assertOk()->json('data.data');
        $this->assertNotContains($studentId, array_column($miss, 'id'));
    }
}
