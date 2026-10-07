<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Center-scoped seasons: own-center writes only; hizb reference is gone. */
class SeasonCenterTest extends TestCase
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

    public function test_hizb_reference_table_is_gone(): void
    {
        $this->assertFalse(Schema::hasTable('quran_hizb_reference'));
        $this->assertFalse(class_exists(\App\Models\QuranHizb::class, false));
        $this->assertFileDoesNotExist(app_path('Models/QuranHizb.php'));
    }

    public function test_supervisor_create_forces_own_center_and_admin_must_pick(): void
    {
        $this->actingAs(User::find(2), 'api'); // supervisor, center 1
        $id = $this->postJson('/api/v1/seasons', [
            'name' => 'Sup Season', 'start_date' => '2026-09-01',
        ])->assertCreated()->json('data.id');
        $this->assertSame(1, DB::table('academic_seasons')->where('id', $id)->value('center_id'));

        $this->actingAs(User::find(1), 'api');
        $this->postJson('/api/v1/seasons', [
            'name' => 'Centerless Season', 'start_date' => '2026-09-01',
        ])->assertStatus(422)->assertJsonValidationErrors('center_id');
    }

    public function test_supervisor_cannot_touch_other_centers_seasons(): void
    {
        $this->actingAs(User::find(1), 'api');
        $other = $this->postJson('/api/v1/seasons', [
            'name' => 'C2 Season', 'start_date' => '2026-09-01', 'center_id' => 2,
        ])->assertCreated()->json('data.id');

        $this->actingAs(User::find(2), 'api'); // supervisor, center 1
        $this->putJson("/api/v1/seasons/{$other}", ['name' => 'Hijacked'])->assertForbidden();
        $this->postJson("/api/v1/seasons/{$other}/activate")->assertForbidden();
        $termId = DB::table('terms')->where('season_id', $other)->value('id');
        $this->patchJson("/api/v1/terms/{$termId}", ['name_ar' => 'Hijacked'])->assertForbidden();
    }

    public function test_season_index_hides_foreign_centers(): void
    {
        $this->actingAs(User::find(1), 'api');
        $this->postJson('/api/v1/seasons', [
            'name' => 'C2 Listed', 'start_date' => '2026-09-01', 'center_id' => 2,
        ])->assertCreated();

        $this->actingAs(User::find(2), 'api'); // supervisor, center 1
        $names = collect($this->getJson('/api/v1/seasons')->assertOk()->json('data.data'))
            ->pluck('name');
        $this->assertNotContains('C2 Listed', $names);
    }
}
