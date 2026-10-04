<?php

namespace Tests\Feature;

use App\Models\ScoringModule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Item 10: scoring-module writes are admin-only; delete needs zero scores. Self-contained (fresh-DB safe). */
class ScoringModuleManageTest extends TestCase
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

    public function test_admin_can_create_and_delete_unused_module(): void
    {
        $this->actingAs(User::find(1), 'api');
        $id = $this->postJson('/api/v1/scoring-modules', [
            'code' => 'e2e_book', 'name_ar' => 'E2E', 'max_points' => 5,
            'scope' => 'weekly', 'is_active' => false, 'is_in_weekly_total' => false,
        ])->assertCreated()->json('data.id');
        $this->deleteJson("/api/v1/scoring-modules/{$id}")->assertOk();
        $this->assertNull(ScoringModule::find($id));
    }

    public function test_delete_refused_when_scores_exist(): void
    {
        $admin = User::find(1);
        $this->actingAs($admin, 'api');
        $id = $this->postJson('/api/v1/scoring-modules', [
            'code' => 'refused_book', 'name_ar' => 'Refused', 'max_points' => 5,
            'scope' => 'weekly', 'is_active' => false, 'is_in_weekly_total' => false,
        ])->assertCreated()->json('data.id');

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            DB::table('session_scores')->insert([
                'student_id' => 999999, 'season_id' => 1, 'term_id' => 1,
                'week_id' => 1, 'session_id' => 999999, 'log_date' => now()->toDateString(),
                'module_id' => $id, 'score' => 5,
            ]);
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $this->deleteJson("/api/v1/scoring-modules/{$id}")
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'MODULE_HAS_SCORES');
    }

    public function test_supervisor_and_teacher_cannot_write_modules(): void
    {
        $centerId = DB::table('centers')->value('id') ?? 1;
        $sup = User::create([
            'full_name' => 'Mod Sup', 'email' => 'modsup@example.org',
            'password_hash' => Hash::make('password123'),
            'role' => 'supervisor', 'center_id' => $centerId, 'is_active' => true,
        ]);
        $tea = User::create([
            'full_name' => 'Mod Tea', 'email' => 'modtea@example.org',
            'password_hash' => Hash::make('password123'),
            'role' => 'teacher', 'center_id' => $centerId, 'is_active' => true,
        ]);

        $this->actingAs($sup, 'api');
        $this->postJson('/api/v1/scoring-modules', [
            'code' => 'nope', 'name_ar' => 'Nope', 'max_points' => 1, 'scope' => 'weekly',
        ])->assertForbidden();
        $this->actingAs($tea, 'api');
        $this->putJson('/api/v1/scoring-modules', ['modules' => []])
            ->assertForbidden();
    }
}
