<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** T4: /auth/me and login carry center_name so the header chip needs no extra request. */
class AuthCenterNameTest extends TestCase
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

    public function test_me_carries_center_name(): void
    {
        $this->actingAs(User::find(1), 'api');
        $this->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.center_id', null)
            ->assertJsonPath('data.center_name', null);

        $user = User::find(2);
        $this->assertNotNull($user->center_id);
        $this->actingAs($user, 'api');
        $this->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.center_id', $user->center_id)
            ->assertJsonPath('data.center_name', $user->center?->name);
    }

    public function test_login_payload_carries_center_name(): void
    {
        $user = User::find(2);
        $user->password_hash = Hash::make('password123');
        $user->save();
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'password123',
        ])->assertOk()
            ->assertJsonPath('data.user.center_id', $user->center_id)
            ->assertJsonPath('data.user.center_name', $user->center?->name);
    }
}
