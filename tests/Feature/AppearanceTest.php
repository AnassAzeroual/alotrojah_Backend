<?php

namespace Tests\Feature;

use App\Models\AppearanceSetting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** App-wide background/pattern uploads: admin writes, every role reads. */
class AppearanceTest extends TestCase
{
    /** Real 1x1 PNG — this host has no GD, so fake()->image() cannot be used. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_admin_uploads_both_layers_and_show_returns_absolute_urls(): void
    {
        $this->actingAs(User::find(1), 'api');

        $this->upload('background', 'bg.png')
            ->assertOk()
            ->assertJsonPath('data.pattern_url', null)
            ->assertJsonStructure(['data' => ['background_url', 'pattern_url', 'updated_at']]);

        $this->upload('pattern', 'pattern.png')
            ->assertOk()
            ->assertJsonPath('data.pattern_url', fn (string $url) => str_contains($url, '/api/v1/appearance/files/pattern/'));

        $setting = AppearanceSetting::current();
        Storage::disk('public')->assertExists($setting->background_path);
        Storage::disk('public')->assertExists($setting->pattern_path);

        $this->getJson('/api/v1/appearance')
            ->assertOk()
            ->assertJsonPath('data.background_url', fn (string $url) => str_ends_with($url, basename($setting->background_path)))
            ->assertJsonPath('data.pattern_url', fn (string $url) => str_ends_with($url, basename($setting->pattern_path)));
    }

    public function test_replacing_a_layer_deletes_the_previous_file(): void
    {
        $this->actingAs(User::find(1), 'api');

        $this->upload('background', 'first.png')->assertOk();
        $first = AppearanceSetting::current()->background_path;

        $this->upload('background', 'second.png')->assertOk();
        $second = AppearanceSetting::current()->background_path;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
        $this->assertCount(1, Storage::disk('public')->allFiles('appearance/background'));
    }

    public function test_removing_a_layer_clears_url_and_file_and_is_idempotent(): void
    {
        $this->actingAs(User::find(1), 'api');

        $this->upload('pattern', 'pattern.png')->assertOk();
        $path = AppearanceSetting::current()->pattern_path;

        $this->deleteJson('/api/v1/appearance/pattern')
            ->assertOk()
            ->assertJsonPath('data.pattern_url', null);
        Storage::disk('public')->assertMissing($path);

        $this->deleteJson('/api/v1/appearance/pattern')
            ->assertOk()
            ->assertJsonPath('data.pattern_url', null);
    }

    public function test_non_admin_cannot_upload_or_remove_but_reads(): void
    {
        $this->actingAs(User::find(2), 'api');

        $this->getJson('/api/v1/appearance')->assertOk();
        $this->upload('background', 'bg.png')->assertForbidden();
        $this->deleteJson('/api/v1/appearance/background')->assertForbidden();

        $this->assertNull(AppearanceSetting::current()->background_path);
    }

    public function test_invalid_file_is_rejected(): void
    {
        $this->actingAs(User::find(1), 'api');

        $txt = UploadedFile::fake()->createWithContent('evil.txt', 'not an image');
        $this->post('/api/v1/appearance/background', ['image' => $txt], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');

        $this->assertNull(AppearanceSetting::current()->background_path);
    }

    public function test_unknown_kind_is_not_routable(): void
    {
        $this->actingAs(User::find(1), 'api');

        $this->upload('avatar', 'bg.png')->assertNotFound();
        $this->deleteJson('/api/v1/appearance/avatar')->assertNotFound();
    }

    public function test_file_endpoint_serves_without_authentication(): void
    {
        $path = 'appearance/background/public-test.png';
        Storage::disk('public')->put($path, base64_decode(self::PNG));
        AppearanceSetting::current()->update(['background_path' => $path]);

        $res = $this->get('/api/v1/appearance/files/background/public-test.png');

        $res->assertOk();
        $this->assertSame('image/png', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('max-age=31536000', (string) $res->headers->get('Cache-Control'));
        $this->get('/api/v1/appearance/files/background/missing.png')->assertNotFound();
        $this->get('/api/v1/appearance/files/avatar/public-test.png')->assertNotFound();
    }

    private function upload(string $kind, string $name)
    {
        $file = UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG));

        return $this->post("/api/v1/appearance/{$kind}", ['image' => $file], ['Accept' => 'application/json']);
    }
}
