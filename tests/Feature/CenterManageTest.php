<?php

namespace Tests\Feature;

use App\Models\Center;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Centers CRUD gates (admin-only writes, delete always refused). */
class CenterManageTest extends TestCase
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

    public function test_admin_can_create_and_rename_center(): void
    {
        $this->actingAs(User::find(1), 'api');
        $id = $this->postJson('/api/v1/centers', ['name' => 'E2E Center'])
            ->assertCreated()->json('data.id');
        $this->assertDatabaseHas('centers', ['id' => $id, 'name' => 'E2E Center']);
        $this->putJson("/api/v1/centers/{$id}", ['name' => 'E2E Center Renamed'])
            ->assertOk()->assertJsonPath('data.name', 'E2E Center Renamed');
    }

    public function test_supervisor_cannot_create_center(): void
    {
        $this->actingAs(User::find(2), 'api')
            ->postJson('/api/v1/centers', ['name' => 'Nope'])
            ->assertForbidden();
    }

    public function test_admin_cannot_delete_center(): void
    {
        $this->actingAs(User::find(1), 'api');
        $id = $this->postJson('/api/v1/centers', ['name' => 'Undeletable'])
            ->assertCreated()->json('data.id');
        $this->deleteJson("/api/v1/centers/{$id}")->assertForbidden();
        $this->assertNotNull(Center::find($id));
    }
}
