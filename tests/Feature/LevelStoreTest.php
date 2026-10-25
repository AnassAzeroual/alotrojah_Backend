<?php

namespace Tests\Feature;

use App\Models\AcademicSeason;
use App\Models\Level;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Dynamic level codes (L{id}): admin-only POST /levels creates a row inside a
 * season; the code is server-generated, never client-supplied.
 */
class LevelStoreTest extends TestCase
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

    private function payload(array $over = []): array
    {
        return array_merge([
            'season_id' => 1,
            'name_ar' => 'L4 Test',
            'sessions_per_week' => 3,
            'thumn_per_session_label' => 'thumn',
            'thumn_per_session_value' => 1,
            'thumn_per_week_value' => 3,
            'ahzab_per_term' => 5,
            'ahzab_per_dawra' => 10,
            'duration_label' => 'term',
            'total_ahzab' => 60,
        ], $over);
    }

    public function test_admin_creates_a_level_inside_a_season_with_dynamic_code(): void
    {
        $this->actingAs(User::find(1), 'api');
        $r = $this->postJson('/api/v1/levels', $this->payload())->assertCreated();
        $id = $r->json('data.id');
        $this->assertSame('L'.$id, $r->json('data.code'));
        $this->assertSame(1, (int) $r->json('data.season_id'));
        $this->assertNull(Level::find($id)->center_id);
    }

    public function test_admin_creates_a_level_in_a_center_season(): void
    {
        $this->actingAs(User::find(1), 'api');
        $season = AcademicSeason::create([
            'name' => 'Center Season '.uniqid(),
            'start_date' => '2026-09-01',
            'total_weeks' => 0,
            'total_sessions' => 0,
            'is_current' => false,
            'center_id' => 1,
        ]);
        $r = $this->postJson('/api/v1/levels', $this->payload(['season_id' => $season->id]))->assertCreated();
        $this->assertSame(1, (int) $r->json('data.center_id'));
        $this->assertStringStartsWith('L', $r->json('data.code'));
    }

    public function test_non_admin_cannot_create(): void
    {
        $teacher = User::where('role', 'teacher')->firstOrFail();
        $this->actingAs($teacher, 'api');
        $this->postJson('/api/v1/levels', $this->payload())->assertForbidden();
    }

    public function test_season_is_required(): void
    {
        $this->actingAs(User::find(1), 'api');
        $payload = $this->payload();
        unset($payload['season_id']);
        $this->postJson('/api/v1/levels', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('season_id');
    }

    public function test_validation_rejects_blank_name(): void
    {
        $this->actingAs(User::find(1), 'api');
        $this->postJson('/api/v1/levels', $this->payload(['name_ar' => '']))->assertUnprocessable();
    }

    public function test_per_session_amount_is_bounded_1_to_480_thumn(): void
    {
        $this->actingAs(User::find(1), 'api');
        // Full Quran (60 ahzab = 480 thumn) is the ceiling, 1 thumn the floor.
        $this->postJson('/api/v1/levels', $this->payload(['thumn_per_session_value' => 0]))
            ->assertUnprocessable();
        $this->postJson('/api/v1/levels', $this->payload(['thumn_per_session_value' => 481]))
            ->assertUnprocessable();
        $this->postJson('/api/v1/levels', $this->payload(['thumn_per_session_value' => 480]))
            ->assertCreated();
    }

    public function test_client_code_is_ignored(): void
    {
        $this->actingAs(User::find(1), 'api');
        $r = $this->postJson('/api/v1/levels', $this->payload(['code' => 'L1']))->assertCreated();
        $this->assertNotSame('L1', $r->json('data.code'));
    }

    public function test_identical_level_in_the_same_season_is_refused(): void
    {
        $this->actingAs(User::find(1), 'api');
        $payload = $this->payload(['name_ar' => 'Twin Test']);
        $this->postJson('/api/v1/levels', $payload)->assertCreated();
        $this->postJson('/api/v1/levels', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('errors.code', 'LEVEL_EXISTS');
    }

    public function test_same_values_in_another_season_are_allowed(): void
    {
        $this->actingAs(User::find(1), 'api');
        $season = AcademicSeason::create([
            'name' => 'Other Season '.uniqid(),
            'start_date' => '2026-09-01',
            'total_weeks' => 0,
            'total_sessions' => 0,
            'is_current' => false,
        ]);
        $this->postJson('/api/v1/levels', $this->payload(['name_ar' => 'Scope A']))->assertCreated();
        $this->postJson('/api/v1/levels', $this->payload(['name_ar' => 'Scope B', 'season_id' => $season->id]))
            ->assertCreated();
    }
}
