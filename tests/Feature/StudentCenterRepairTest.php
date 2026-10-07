<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * §2.1/B1: NULL-center pupils must be repairable by the admin through the
 * API (and hence the UI). Multi-center omit→NULL is locked as-is; the
 * single-center fallback is proven live on the audit harness instead.
 */
class StudentCenterRepairTest extends TestCase
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

    public function test_admin_can_repair_null_center_and_assign_group(): void
    {
        $this->actingAs(User::find(1), 'api');
        // Multi-center install: omitting the center still stores NULL.
        $id = $this->postJson('/api/v1/students', [
            'full_name' => 'Repair Me', 'memorization_mode' => 'thumn', 'status' => 'active',
        ])->assertCreated()->json('data.id');
        $this->assertNull(Student::find($id)->center_id);

        // §2.2: per_page is honored (clamped 1..100), not silently 20.
        $this->getJson('/api/v1/students?per_page=100')->assertOk()->assertJsonPath('data.meta.per_page', 100);
        $this->getJson('/api/v1/students?per_page=500')->assertOk()->assertJsonPath('data.meta.per_page', 100);
        $this->getJson('/api/v1/students')->assertOk()->assertJsonPath('data.meta.per_page', 20);

        // Repair: admin sets the center.
        $this->putJson("/api/v1/students/{$id}", ['center_id' => 1])
            ->assertOk()->assertJsonPath('data.center_id', 1);
        $this->assertSame(1, Student::find($id)->center_id);

        // Group assignment now passes the center check in the same payload shape.
        $this->putJson("/api/v1/students/{$id}", ['center_id' => 1, 'group_id' => 1])
            ->assertOk()->assertJsonPath('data.group.id', 1);
    }

    public function test_non_admin_cannot_move_center(): void
    {
        $sup = User::find(2);
        $this->actingAs($sup, 'api');
        $id = $this->postJson('/api/v1/students', [
            'full_name' => 'Sup Pupil', 'memorization_mode' => 'thumn', 'status' => 'active',
        ])->assertCreated()->json('data.id');
        $this->assertSame((int) $sup->center_id, (int) Student::find($id)->center_id);

        $this->putJson("/api/v1/students/{$id}", ['center_id' => 999])
            ->assertStatus(422); // exists: rule still guards the value…
        $this->putJson("/api/v1/students/{$id}", ['center_id' => 2])
            ->assertOk();
        $this->assertSame((int) $sup->center_id, (int) Student::find($id)->center_id);
    }
}
