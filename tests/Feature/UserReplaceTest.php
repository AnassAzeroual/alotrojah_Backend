<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\DelegationToken;
use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Teacher delete with replacer flow. Transaction-wrapped (seed untouched).
 * NOTE: actingAs() — never two JWT tokens in one test (guard caches the user).
 */
class UserReplaceTest extends TestCase
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

    private function makeTeacher(string $email, string $type = 'hifz', int $center = 1): User
    {
        return User::create([
            'full_name' => "Replace {$email}", 'email' => $email,
            'password_hash' => Hash::make('password123'), 'role' => 'teacher',
            'center_id' => $center, 'teacher_type' => $type, 'is_active' => true,
        ]);
    }

    private function makeGroup(int $teacherId, int $center = 1, bool $active = true): Group
    {
        return Group::create([
            'name' => "Replace Group {$teacherId}".($active ? '' : ' old'),
            'center_id' => $center, 'level_id' => 1, 'teacher_id' => $teacherId,
            'is_active' => $active,
        ]);
    }

    public function test_admin_deletes_reference_free_teacher(): void
    {
        $t = $this->makeTeacher('free@example.org');
        $this->actingAs(User::find(1), 'api')
            ->deleteJson("/api/v1/users/{$t->id}")
            ->assertOk();
        $this->assertNull(User::find($t->id));
    }

    public function test_delete_teacher_with_active_group_needs_replacer(): void
    {
        $t = $this->makeTeacher('busy@example.org');
        $g = $this->makeGroup($t->id);
        $this->actingAs(User::find(1), 'api')
            ->deleteJson("/api/v1/users/{$t->id}")
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'NEED_REPLACER')
            ->assertJsonPath('errors.groups.0.id', $g->id);
        $this->assertNotNull(User::find($t->id));
    }

    public function test_replace_moves_groups_and_deletes(): void
    {
        $old = $this->makeTeacher('old@example.org', 'hifz');
        $new = $this->makeTeacher('new@example.org', 'hifz');
        $g = $this->makeGroup($old->id);
        Announcement::create([
            'author_id' => $old->id, 'audience' => 'all', 'title' => 'Gone', 'body' => 'Gone',
        ]);

        $this->actingAs(User::find(1), 'api')
            ->postJson("/api/v1/users/{$old->id}/replace", ['replacer_id' => $new->id])
            ->assertOk()
            ->assertJsonPath('data.replacer_id', $new->id);

        $this->assertNull(User::find($old->id));
        $this->assertEquals($new->id, Group::find($g->id)->teacher_id);
        $this->assertEquals(0, Announcement::where('author_id', $old->id)->count());
    }

    public function test_replace_rejects_wrong_type(): void
    {
        $old = $this->makeTeacher('old2@example.org', 'murajaa');
        $new = $this->makeTeacher('new2@example.org', 'hifz');
        $this->makeGroup($old->id);
        $this->actingAs(User::find(1), 'api')
            ->postJson("/api/v1/users/{$old->id}/replace", ['replacer_id' => $new->id])
            ->assertStatus(422);
        $this->assertNotNull(User::find($old->id));
    }

    public function test_replace_rejects_busy_replacer(): void
    {
        $old = $this->makeTeacher('old3@example.org', 'hifz');
        $new = $this->makeTeacher('new3@example.org', 'both');
        $this->makeGroup($old->id);
        $this->makeGroup($new->id);
        $this->actingAs(User::find(1), 'api')
            ->postJson("/api/v1/users/{$old->id}/replace", ['replacer_id' => $new->id])
            ->assertStatus(422);
        $this->assertNotNull(User::find($old->id));
    }

    public function test_replace_accepts_free_replacer_with_inactive_group(): void
    {
        $old = $this->makeTeacher('old4@example.org', 'both');
        $new = $this->makeTeacher('new4@example.org', 'both');
        $this->makeGroup($old->id);
        $this->makeGroup($new->id, 1, false); // inactive group does not block
        $this->actingAs(User::find(1), 'api')
            ->postJson("/api/v1/users/{$old->id}/replace", ['replacer_id' => $new->id])
            ->assertOk();
        $this->assertNull(User::find($old->id));
    }

    public function test_supervisor_cannot_delete_or_replace(): void
    {
        $t = $this->makeTeacher('sup@example.org');
        $this->actingAs(User::find(2), 'api');
        $this->deleteJson("/api/v1/users/{$t->id}")->assertForbidden();
        $r = $this->makeTeacher('sup2@example.org');
        $this->postJson("/api/v1/users/{$t->id}/replace", ['replacer_id' => $r->id])
            ->assertForbidden();
    }

    public function test_delete_removes_tokens_and_nulls_inactive_groups(): void
    {
        $old = $this->makeTeacher('old5@example.org', 'hifz');
        $g = $this->makeGroup($old->id, 1, false); // inactive only → direct delete
        DelegationToken::create([
            'group_id' => $g->id, 'granter_teacher_id' => $old->id,
            'token' => hash('sha256', 'tok-old5'), 'duration_minutes' => 30,
            'expires_at' => now()->addHour(),
        ]);
        $this->actingAs(User::find(1), 'api')
            ->deleteJson("/api/v1/users/{$old->id}")
            ->assertOk();
        $this->assertNull(User::find($old->id));
        $this->assertNull(Group::find($g->id)->teacher_id);
        $this->assertEquals(0, DelegationToken::where('granter_teacher_id', $old->id)->count());
    }
}
