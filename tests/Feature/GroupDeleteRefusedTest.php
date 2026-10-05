<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** §2.6: groups are never deleted — admins get an explicit refusal, others a policy 403. */
class GroupDeleteRefusedTest extends TestCase
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

    public function test_admin_gets_explicit_refusal_and_group_survives(): void
    {
        $group = Group::firstOrFail();
        $this->actingAs(User::find(1), 'api')
            ->deleteJson("/api/v1/groups/{$group->id}")
            ->assertForbidden()
            ->assertJsonPath('errors.code', 'ADMIN_ONLY');
        $this->assertNotNull(Group::find($group->id));
    }

    public function test_teacher_gets_policy_refusal(): void
    {
        $this->actingAs(User::find(3), 'api')
            ->deleteJson('/api/v1/groups/1')
            ->assertForbidden();
    }
}
