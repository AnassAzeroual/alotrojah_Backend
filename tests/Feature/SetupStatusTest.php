<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The admin setup guide reads one ordered checklist from /setup/status. */
class SetupStatusTest extends TestCase
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

    public function test_admin_gets_the_six_step_checklist(): void
    {
        $this->actingAs(User::find(1), 'api');

        $response = $this->getJson('/api/v1/setup/status')->assertOk();
        $response->assertJsonPath('data.total_steps', 6);
        $response->assertJsonCount(6, 'data.steps');

        $steps = $response->json('data.steps');
        $this->assertSame(
            ['centers', 'season', 'levels', 'teachers', 'groups', 'pupils'],
            array_column($steps, 'key')
        );
        foreach ($steps as $step) {
            $this->assertIsBool($step['done']);
            $this->assertIsInt($step['count']);
            $this->assertStringStartsWith('/', $step['target']);
        }

        $current = $response->json('data.current');
        $firstUndone = collect($steps)->firstWhere('done', false)['key'] ?? null;
        $this->assertSame($firstUndone, $current);
        $this->assertGreaterThanOrEqual(0, $response->json('data.done_steps'));
        $this->assertLessThanOrEqual(6, $response->json('data.done_steps'));
    }

    public function test_non_admin_is_forbidden(): void
    {
        $this->actingAs(User::find(3), 'api');
        $this->getJson('/api/v1/setup/status')->assertForbidden();
    }
}
