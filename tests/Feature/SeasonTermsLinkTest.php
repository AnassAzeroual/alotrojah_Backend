<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** §2.4: season payloads carry their own first term id (no season/term id mix-up). */
class SeasonTermsLinkTest extends TestCase
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

    public function test_each_season_links_to_its_own_first_term(): void
    {
        $this->actingAs(User::find(1), 'api');
        $rows = $this->getJson('/api/v1/seasons')->assertOk()->json('data.data');
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertArrayHasKey('first_term_id', $row);
            if ((int) $row['terms_count'] === 0) {
                $this->assertNull($row['first_term_id']);
                continue;
            }
            $this->assertNotNull($row['first_term_id']);
            $this->assertSame(
                (int) $row['id'],
                (int) DB::table('terms')->where('id', $row['first_term_id'])->value('season_id')
            );
        }
    }
}
