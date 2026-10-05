<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** §2.8: non-admin accounts require a center; only the global admin may be center-less. */
class UserCenterRequiredTest extends TestCase
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

    private function payload(string $role, ?int $center): array
    {
        return [
            'full_name' => "Center Check $role", 'email' => "cc-$role@example.org",
            'password' => 'password123', 'role' => $role, 'center_id' => $center,
        ];
    }

    public function test_non_admin_roles_require_a_center(): void
    {
        $this->actingAs(User::find(1), 'api');
        foreach (['supervisor', 'teacher', 'board', 'student'] as $role) {
            $this->postJson('/api/v1/users', $this->payload($role, null))
                ->assertStatus(422)
                ->assertJsonValidationErrors('center_id');
            $this->assertDatabaseMissing('users', ['email' => "cc-$role@example.org"]);
        }
    }

    public function test_centered_create_and_global_admin_still_work(): void
    {
        $this->actingAs(User::find(1), 'api');
        $this->postJson('/api/v1/users', $this->payload('board', 1))
            ->assertCreated()->assertJsonPath('data.center_id', 1);
        $this->postJson('/api/v1/users', $this->payload('admin', null))
            ->assertCreated()->assertJsonPath('data.center_id', null);
    }
}
