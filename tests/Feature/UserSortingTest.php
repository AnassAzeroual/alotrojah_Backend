<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Whitelisted server-side sort for the users directory (drives the frontend
 * list-table sort headers). Transaction-wrapped (seed untouched).
 * Orders are compared against direct SQL ordering so collation semantics stay
 * the DB's, not PHP's.
 */
class UserSortingTest extends TestCase
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

    private function admin(): User
    {
        return User::where('role', 'admin')->firstOrFail();
    }

    public function test_admin_can_sort_users_by_full_name_asc(): void
    {
        $ids = collect($this->actingAs($this->admin(), 'api')
            ->getJson('/api/v1/users?sort=full_name&direction=asc')
            ->assertOk()
            ->json('data.data'))->pluck('id')->all();

        $expected = DB::table('users')->orderBy('full_name', 'asc')->orderBy('id')
            ->limit(20)->pluck('id')->all();

        $this->assertSame($expected, $ids);
    }

    public function test_admin_can_sort_users_by_full_name_desc(): void
    {
        $ids = collect($this->actingAs($this->admin(), 'api')
            ->getJson('/api/v1/users?sort=full_name&direction=desc')
            ->assertOk()
            ->json('data.data'))->pluck('id')->all();

        $expected = DB::table('users')->orderBy('full_name', 'desc')->orderBy('id')
            ->limit(20)->pluck('id')->all();

        $this->assertSame($expected, $ids);
    }

    public function test_unknown_sort_key_falls_back_to_id_order(): void
    {
        $ids = collect($this->actingAs($this->admin(), 'api')
            ->getJson('/api/v1/users?sort=password_hash&direction=asc')
            ->assertOk()
            ->json('data.data'))->pluck('id')->all();

        $expected = DB::table('users')->orderBy('id')->limit(20)->pluck('id')->all();

        $this->assertSame($expected, $ids);
    }
}