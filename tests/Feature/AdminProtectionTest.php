<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Admin account protection: admins are never deletable (UI hides the button,
 * API refuses with a translated code), admins cannot self-deactivate, and the
 * last active admin cannot be deactivated. Non-admin self-deactivation stays
 * allowed (frontend confirms, then logs out).
 */
class AdminProtectionTest extends TestCase
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

    private function makeAdmin(string $email): int
    {
        return $this->postJson('/api/v1/users', [
            'full_name' => 'Second Admin', 'email' => $email,
            'password' => 'password123', 'role' => 'admin',
        ])->assertCreated()->json('data.id');
    }

    private function makeSupervisor(string $email): int
    {
        return $this->postJson('/api/v1/users', [
            'full_name' => 'Deactivating Supervisor', 'email' => $email,
            'password' => 'password123', 'role' => 'supervisor', 'center_id' => 1,
        ])->assertCreated()->json('data.id');
    }

    public function test_admin_accounts_cannot_be_deleted(): void
    {
        $this->actingAs(User::find(1), 'api');
        $other = $this->makeAdmin('second.admin@example.org');
        $this->deleteJson("/api/v1/users/{$other}")
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'ADMIN_DELETE');
        $this->assertNotNull(User::find($other));
        // Replace-flow deletes too — same refusal.
        $this->postJson("/api/v1/users/{$other}/replace", ['replacer_id' => 1])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'ADMIN_DELETE');
        // Self-delete stays a policy 403, not the 422 (precedence pinned).
        $this->deleteJson('/api/v1/users/1')->assertForbidden();
    }

    public function test_non_admin_cannot_delete_admin(): void
    {
        $this->actingAs(User::find(1), 'api');
        $sup = $this->makeSupervisor('sup.protect@example.org');
        $this->actingAs(User::find($sup), 'api');
        $this->deleteJson('/api/v1/users/1')->assertForbidden();
    }

    public function test_admin_cannot_self_deactivate(): void
    {
        $this->actingAs(User::find(1), 'api');
        $this->putJson('/api/v1/users/1', ['is_active' => false])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'ADMIN_SELF_DISABLE');
        $this->assertTrue((bool) User::find(1)->is_active);
    }

    public function test_admin_can_deactivate_other_admin(): void
    {
        $this->actingAs(User::find(1), 'api');
        $other = $this->makeAdmin('third.admin@example.org');
        $this->putJson("/api/v1/users/{$other}", ['is_active' => false])->assertOk();
        $this->assertFalse((bool) User::find($other)->is_active);
    }

    public function test_non_admin_self_deactivation_allowed(): void
    {
        $this->actingAs(User::find(1), 'api');
        $sup = $this->makeSupervisor('sup.selfoff@example.org');
        $this->actingAs(User::find($sup), 'api');
        $this->putJson("/api/v1/users/{$sup}", ['is_active' => false])->assertOk();
        $this->assertFalse((bool) User::find($sup)->is_active);
        // One-way: self reactivation is stripped, never applied.
        $this->putJson("/api/v1/users/{$sup}", ['is_active' => true])->assertOk();
        $this->assertFalse((bool) User::find($sup)->is_active);
    }

    public function test_last_active_admin_refused(): void
    {
        $this->actingAs(User::find(1), 'api');
        $other = $this->makeAdmin('fourth.admin@example.org');
        // Leave $other as the sole active admin (actingAs bypasses is_active).
        User::where('role', 'admin')->where('id', '!=', $other)->update(['is_active' => false]);
        $stale = User::find(1);
        $this->actingAs($stale, 'api');
        $this->putJson("/api/v1/users/{$other}", ['is_active' => false])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'ADMIN_LAST_ACTIVE');
        $this->assertTrue((bool) User::find($other)->is_active);
    }
}
