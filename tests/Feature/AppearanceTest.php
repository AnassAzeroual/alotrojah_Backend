<?php

namespace Tests\Feature;

use App\Models\AppearanceSetting;
use App\Models\Center;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Per-center background/pattern uploads: admins write any center, everyone reads their own. */
class AppearanceTest extends TestCase
{
    /** Real 1x1 PNG — this host has no GD, so fake()->image() cannot be used. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private int $centerA;

    private int $centerB;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        Storage::fake('public');
        DB::table('appearance_settings')->delete();

        $centers = Center::withoutGlobalScopes()->orderBy('id')->pluck('id');
        $this->assertGreaterThanOrEqual(2, $centers->count(), 'The canonical seed needs two centers.');
        $this->centerA = (int) $centers[0];
        $this->centerB = (int) $centers[1];
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_show_returns_defaults_when_a_center_has_no_row(): void
    {
        $this->actingAs(User::findOrFail(1), 'api');

        $this->getJson("/api/v1/appearance?center_id={$this->centerA}")
            ->assertOk()
            ->assertJsonPath('data.center_id', $this->centerA)
            ->assertJsonPath('data.background_url', null)
            ->assertJsonPath('data.background_type', 'none')
            ->assertJsonPath('data.background_color', '#0e9f6e')
            ->assertJsonPath('data.gradient_type', 'linear')
            ->assertJsonPath('data.gradient_angle', 135)
            ->assertJsonPath('data.gradient_from', '#0e9f6e')
            ->assertJsonPath('data.gradient_to', '#0b3b2f')
            ->assertJsonPath('data.background_opacity', 100)
            ->assertJsonPath('data.background_size', 'cover')
            ->assertJsonPath('data.background_position', 'center')
            ->assertJsonPath('data.background_repeat', 'no-repeat')
            ->assertJsonPath('data.background_attachment', 'scroll')
            ->assertJsonPath('data.background_blend_mode', 'normal')
            ->assertJsonPath('data.pattern_url', null)
            ->assertJsonPath('data.pattern_enabled', true)
            ->assertJsonPath('data.pattern_opacity', 16)
            ->assertJsonPath('data.pattern_size', 120)
            ->assertJsonPath('data.pattern_tint', null)
            ->assertJsonPath('data.pattern_invert', false)
            ->assertJsonPath('data.backdrop_blur', 0)
            ->assertJsonPath('data.glass_tint', '#ffffff')
            ->assertJsonPath('data.glass_opacity', 0)
            ->assertJsonPath('data.orb_enabled', true)
            ->assertJsonPath('data.orb_primary', '#00b8a9')
            ->assertJsonPath('data.orb_secondary', '#8b5cf6')
            ->assertJsonPath('data.orb_opacity', 50)
            ->assertJsonPath('data.scrim_enabled', true)
            ->assertJsonPath('data.scrim_color', null)
            ->assertJsonPath('data.scrim_opacity', 72)
            ->assertJsonStructure([
                'data' => [
                    'center_id',
                    'background_url',
                    'background_type',
                    'background_color',
                    'gradient_type',
                    'gradient_angle',
                    'gradient_from',
                    'gradient_to',
                    'background_opacity',
                    'background_size',
                    'background_position',
                    'background_repeat',
                    'background_attachment',
                    'background_blend_mode',
                    'pattern_url',
                    'pattern_enabled',
                    'pattern_opacity',
                    'pattern_size',
                    'pattern_tint',
                    'pattern_invert',
                    'backdrop_blur',
                    'glass_tint',
                    'glass_opacity',
                    'orb_enabled',
                    'orb_primary',
                    'orb_secondary',
                    'orb_opacity',
                    'scrim_enabled',
                    'scrim_color',
                    'scrim_opacity',
                    'updated_at',
                ],
            ]);
    }

    public function test_admin_uploads_both_layers_for_a_center_and_show_returns_absolute_urls(): void
    {
        $this->actingAs(User::findOrFail(1), 'api');

        $this->upload($this->centerA, 'background', 'bg.png')
            ->assertOk()
            ->assertJsonPath('data.center_id', $this->centerA)
            ->assertJsonPath('data.pattern_url', null);

        $this->upload($this->centerA, 'pattern', 'pattern.png')
            ->assertOk()
            ->assertJsonPath('data.pattern_url', fn (string $url) => str_contains($url, '/api/v1/appearance/files/pattern/'));

        $setting = AppearanceSetting::forCenter($this->centerA);
        $this->assertNotNull($setting);
        Storage::disk('public')->assertExists($setting->background_path);
        Storage::disk('public')->assertExists($setting->pattern_path);

        $this->getJson("/api/v1/appearance?center_id={$this->centerA}")
            ->assertOk()
            ->assertJsonPath('data.center_id', $this->centerA)
            ->assertJsonPath('data.background_url', fn (string $url) => str_ends_with($url, basename($setting->background_path)))
            ->assertJsonPath('data.pattern_url', fn (string $url) => str_ends_with($url, basename($setting->pattern_path)));
    }

    public function test_replacing_a_layer_deletes_the_previous_file(): void
    {
        $this->actingAs(User::findOrFail(1), 'api');

        $this->upload($this->centerA, 'background', 'first.png')->assertOk();
        $first = AppearanceSetting::forCenter($this->centerA)->background_path;

        $this->upload($this->centerA, 'background', 'second.png')->assertOk();
        $second = AppearanceSetting::forCenter($this->centerA)->background_path;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
        $this->assertCount(1, Storage::disk('public')->allFiles('appearance/background'));
    }

    public function test_removing_a_layer_clears_url_and_file_and_is_idempotent(): void
    {
        $this->actingAs(User::findOrFail(1), 'api');

        $this->upload($this->centerA, 'pattern', 'pattern.png')->assertOk();
        $path = AppearanceSetting::forCenter($this->centerA)->pattern_path;

        $this->deleteJson("/api/v1/appearance/pattern?center_id={$this->centerA}")
            ->assertOk()
            ->assertJsonPath('data.pattern_url', null);
        Storage::disk('public')->assertMissing($path);

        $this->deleteJson("/api/v1/appearance/pattern?center_id={$this->centerA}")
            ->assertOk()
            ->assertJsonPath('data.pattern_url', null);
    }

    public function test_centers_are_fully_isolated(): void
    {
        $this->actingAs(User::findOrFail(1), 'api');

        $this->upload($this->centerA, 'background', 'a.png')->assertOk();
        $this->patchJson('/api/v1/appearance', [
            'center_id' => $this->centerA,
            'background_opacity' => 45,
            'pattern_size' => 300,
            'orb_enabled' => false,
            'orb_primary' => '#112233',
            'orb_opacity' => 20,
            'scrim_enabled' => false,
            'scrim_color' => '#334455',
            'scrim_opacity' => 30,
        ])->assertOk();

        // The untouched center keeps the defaults and no image.
        $this->getJson("/api/v1/appearance?center_id={$this->centerB}")
            ->assertOk()
            ->assertJsonPath('data.background_url', null)
            ->assertJsonPath('data.background_opacity', 100)
            ->assertJsonPath('data.pattern_size', 120)
            ->assertJsonPath('data.orb_enabled', true)
            ->assertJsonPath('data.orb_primary', '#00b8a9')
            ->assertJsonPath('data.orb_opacity', 50)
            ->assertJsonPath('data.scrim_enabled', true)
            ->assertJsonPath('data.scrim_color', null)
            ->assertJsonPath('data.scrim_opacity', 72);

        $this->assertNull(AppearanceSetting::forCenter($this->centerB));

        // And a write to the second center never touches the first.
        $this->patchJson('/api/v1/appearance', [
            'center_id' => $this->centerB,
            'pattern_size' => 500,
        ])->assertOk();

        $this->assertSame(300, AppearanceSetting::forCenter($this->centerA)->pattern_size);
        $this->assertSame(500, AppearanceSetting::forCenter($this->centerB)->pattern_size);
        $this->assertNotNull(AppearanceSetting::forCenter($this->centerA)->background_path);
        $this->assertNull(AppearanceSetting::forCenter($this->centerB)->background_path);
    }

    public function test_non_admin_reads_only_their_own_center_and_cannot_write(): void
    {
        $reader = User::findOrFail(2); // supervisor, center A in the canonical seed
        $this->assertSame($this->centerA, (int) $reader->center_id);

        $this->actingAs(User::findOrFail(1), 'api');
        $this->patchJson('/api/v1/appearance', [
            'center_id' => $this->centerA,
            'pattern_size' => 210,
        ])->assertOk();
        $this->patchJson('/api/v1/appearance', [
            'center_id' => $this->centerB,
            'pattern_size' => 410,
        ])->assertOk();

        $this->actingAs($reader, 'api');

        // Even an explicit foreign center_id is ignored for non-admins.
        $this->getJson("/api/v1/appearance?center_id={$this->centerB}")
            ->assertOk()
            ->assertJsonPath('data.center_id', $this->centerA)
            ->assertJsonPath('data.pattern_size', 210);

        $this->upload($this->centerA, 'background', 'bg.png')->assertForbidden();
        $this->patchJson('/api/v1/appearance', [
            'center_id' => $this->centerB,
            'pattern_size' => 999,
        ])->assertForbidden();
        $this->deleteJson("/api/v1/appearance/background?center_id={$this->centerB}")
            ->assertForbidden();

        $this->assertSame(210, AppearanceSetting::forCenter($this->centerA)->pattern_size);
        $this->assertSame(410, AppearanceSetting::forCenter($this->centerB)->pattern_size);
        $this->assertNull(AppearanceSetting::forCenter($this->centerB)->background_path);
    }

    public function test_admin_must_select_a_center_when_their_account_has_none(): void
    {
        $this->actingAs(User::findOrFail(1), 'api'); // global admin: center_id null

        $this->patchJson('/api/v1/appearance', ['pattern_size' => 200])
            ->assertStatus(422)
            ->assertJsonValidationErrors('center_id');
        $this->upload(null, 'background', 'bg.png')
            ->assertStatus(422)
            ->assertJsonValidationErrors('center_id');
        $this->deleteJson('/api/v1/appearance/background')
            ->assertStatus(422)
            ->assertJsonValidationErrors('center_id');

        $this->getJson('/api/v1/appearance')
            ->assertOk()
            ->assertJsonPath('data.center_id', null)
            ->assertJsonPath('data.pattern_size', 120);
    }

    public function test_center_bound_admin_defaults_to_their_own_center(): void
    {
        $admin = User::create([
            'full_name' => 'Center Admin',
            'email' => 'center-admin@test.local',
            'role' => 'admin',
            'center_id' => $this->centerB,
            'password_hash' => bcrypt('password123'),
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'api');

        $this->patchJson('/api/v1/appearance', ['pattern_size' => 333])
            ->assertOk()
            ->assertJsonPath('data.center_id', $this->centerB)
            ->assertJsonPath('data.pattern_size', 333);

        $this->assertNull(AppearanceSetting::forCenter($this->centerA));
        $this->assertSame(333, AppearanceSetting::forCenter($this->centerB)->pattern_size);
    }

    public function test_invalid_center_id_is_rejected(): void
    {
        $this->actingAs(User::findOrFail(1), 'api');

        $this->getJson('/api/v1/appearance?center_id=99999')
            ->assertStatus(422)
            ->assertJsonValidationErrors('center_id');
        $this->patchJson('/api/v1/appearance', ['center_id' => 99999, 'pattern_size' => 200])
            ->assertStatus(422)
            ->assertJsonValidationErrors('center_id');
        $this->upload(99999, 'background', 'bg.png')
            ->assertStatus(422)
            ->assertJsonValidationErrors('center_id');
    }

    public function test_invalid_file_is_rejected(): void
    {
        $this->actingAs(User::findOrFail(1), 'api');

        $txt = UploadedFile::fake()->createWithContent('evil.txt', 'not an image');
        $this->post(
            "/api/v1/appearance/background?center_id={$this->centerA}",
            ['image' => $txt, 'center_id' => $this->centerA],
            ['Accept' => 'application/json'],
        )->assertStatus(422)->assertJsonValidationErrors('image');

        $this->assertNull(AppearanceSetting::forCenter($this->centerA));
    }

    public function test_unknown_kind_is_not_routable(): void
    {
        $this->actingAs(User::findOrFail(1), 'api');

        $this->upload($this->centerA, 'avatar', 'bg.png')->assertNotFound();
        $this->deleteJson("/api/v1/appearance/avatar?center_id={$this->centerA}")->assertNotFound();
    }

    public function test_file_endpoint_serves_without_authentication(): void
    {
        $path = 'appearance/background/public-test.png';
        Storage::disk('public')->put($path, base64_decode(self::PNG));
        AppearanceSetting::query()->create([
            'center_id' => $this->centerA,
            'background_path' => $path,
        ]);

        $res = $this->get('/api/v1/appearance/files/background/public-test.png');

        $res->assertOk();
        $this->assertSame('image/png', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('max-age=31536000', (string) $res->headers->get('Cache-Control'));
        $this->get('/api/v1/appearance/files/background/missing.png')->assertNotFound();
        $this->get('/api/v1/appearance/files/avatar/public-test.png')->assertNotFound();
    }

    public function test_admin_updates_controls_partially_per_center_and_persists(): void
    {
        $this->actingAs(User::findOrFail(1), 'api');

        $this->patchJson('/api/v1/appearance', [
            'center_id' => $this->centerA,
            'background_opacity' => 55,
            'background_size' => 'contain',
            'background_position' => 'top-right',
            'pattern_opacity' => 42,
            'pattern_size' => 320,
        ])->assertOk()
            ->assertJsonPath('data.center_id', $this->centerA)
            ->assertJsonPath('data.background_opacity', 55)
            ->assertJsonPath('data.background_size', 'contain')
            ->assertJsonPath('data.background_position', 'top-right')
            ->assertJsonPath('data.pattern_opacity', 42)
            ->assertJsonPath('data.pattern_size', 320);

        // A partial patch must leave the fields it does not mention untouched.
        $this->patchJson('/api/v1/appearance', [
            'center_id' => $this->centerA,
            'background_opacity' => 30,
        ])->assertOk()
            ->assertJsonPath('data.background_opacity', 30)
            ->assertJsonPath('data.background_size', 'contain')
            ->assertJsonPath('data.pattern_size', 320);

        $setting = AppearanceSetting::forCenter($this->centerA);
        $this->assertSame(30, $setting->background_opacity);
        $this->assertSame('contain', $setting->background_size);
        $this->assertSame(42, $setting->pattern_opacity);
        $this->assertSame(320, $setting->pattern_size);
    }

    public function test_admin_persists_the_full_background_engine_config_per_center(): void
    {
        $this->actingAs(User::findOrFail(1), 'api');

        $this->patchJson('/api/v1/appearance', [
            'center_id' => $this->centerA,
            'background_type' => 'gradient',
            'gradient_type' => 'conic',
            'gradient_angle' => 270,
            'gradient_from' => '#112233',
            'gradient_to' => '#aabbcc',
            'background_size' => '320px 200px',
            'background_position' => 'right 20px',
            'background_repeat' => 'repeat-x',
            'background_attachment' => 'fixed',
            'background_blend_mode' => 'multiply',
            'pattern_enabled' => false,
            'pattern_tint' => '#ff8800',
            'pattern_invert' => true,
            'backdrop_blur' => 18,
            'glass_tint' => '#102030',
            'glass_opacity' => 42,
            'orb_enabled' => false,
            'orb_primary' => '#223344',
            'orb_secondary' => '#556677',
            'orb_opacity' => 27,
            'scrim_enabled' => false,
            'scrim_color' => '#778899',
            'scrim_opacity' => 41,
        ])->assertOk()
            ->assertJsonPath('data.center_id', $this->centerA)
            ->assertJsonPath('data.background_type', 'gradient')
            ->assertJsonPath('data.gradient_type', 'conic')
            ->assertJsonPath('data.gradient_angle', 270)
            ->assertJsonPath('data.gradient_from', '#112233')
            ->assertJsonPath('data.gradient_to', '#aabbcc')
            ->assertJsonPath('data.background_size', '320px 200px')
            ->assertJsonPath('data.background_position', 'right 20px')
            ->assertJsonPath('data.background_repeat', 'repeat-x')
            ->assertJsonPath('data.background_attachment', 'fixed')
            ->assertJsonPath('data.background_blend_mode', 'multiply')
            ->assertJsonPath('data.pattern_enabled', false)
            ->assertJsonPath('data.pattern_tint', '#ff8800')
            ->assertJsonPath('data.pattern_invert', true)
            ->assertJsonPath('data.backdrop_blur', 18)
            ->assertJsonPath('data.glass_tint', '#102030')
            ->assertJsonPath('data.glass_opacity', 42)
            ->assertJsonPath('data.orb_enabled', false)
            ->assertJsonPath('data.orb_primary', '#223344')
            ->assertJsonPath('data.orb_secondary', '#556677')
            ->assertJsonPath('data.orb_opacity', 27)
            ->assertJsonPath('data.scrim_enabled', false)
            ->assertJsonPath('data.scrim_color', '#778899')
            ->assertJsonPath('data.scrim_opacity', 41);

        $setting = AppearanceSetting::forCenter($this->centerA);
        $this->assertSame('gradient', $setting->background_type);
        $this->assertSame('320px 200px', $setting->background_size);
        $this->assertSame('right 20px', $setting->background_position);
        $this->assertFalse($setting->pattern_enabled);
        $this->assertTrue($setting->pattern_invert);
        $this->assertSame(18, $setting->backdrop_blur);
        $this->assertSame(42, $setting->glass_opacity);
        $this->assertFalse($setting->orb_enabled);
        $this->assertSame('#223344', $setting->orb_primary);
        $this->assertSame('#556677', $setting->orb_secondary);
        $this->assertSame(27, $setting->orb_opacity);
        $this->assertFalse($setting->scrim_enabled);
        $this->assertSame('#778899', $setting->scrim_color);
        $this->assertSame(41, $setting->scrim_opacity);

        // The untouched center keeps every engine default.
        $this->getJson("/api/v1/appearance?center_id={$this->centerB}")
            ->assertOk()
            ->assertJsonPath('data.background_type', 'none')
            ->assertJsonPath('data.gradient_type', 'linear')
            ->assertJsonPath('data.background_blend_mode', 'normal')
            ->assertJsonPath('data.pattern_tint', null)
            ->assertJsonPath('data.backdrop_blur', 0)
            ->assertJsonPath('data.orb_enabled', true)
            ->assertJsonPath('data.orb_primary', '#00b8a9')
            ->assertJsonPath('data.orb_secondary', '#8b5cf6')
            ->assertJsonPath('data.orb_opacity', 50)
            ->assertJsonPath('data.scrim_enabled', true)
            ->assertJsonPath('data.scrim_color', null)
            ->assertJsonPath('data.scrim_opacity', 72);
        $this->assertNull(AppearanceSetting::forCenter($this->centerB));
    }

    public function test_free_form_size_and_position_values_are_accepted(): void
    {
        $this->actingAs(User::findOrFail(1), 'api');

        $cases = [
            ['background_size' => '50%', 'background_position' => '20% 30%'],
            ['background_size' => '320px 200px', 'background_position' => 'right 20px'],
            ['background_size' => 'auto', 'background_position' => 'left center'],
        ];

        foreach ($cases as $payload) {
            $this->patchJson('/api/v1/appearance', $payload + ['center_id' => $this->centerA])
                ->assertOk()
                ->assertJsonPath('data.background_size', $payload['background_size'])
                ->assertJsonPath('data.background_position', $payload['background_position']);
        }
    }

    public function test_upload_activates_image_mode_and_removal_returns_to_none(): void
    {
        $this->actingAs(User::findOrFail(1), 'api');

        $this->patchJson('/api/v1/appearance', [
            'center_id' => $this->centerA,
            'background_type' => 'gradient',
        ])->assertOk();

        $this->upload($this->centerA, 'background', 'bg.png')
            ->assertOk()
            ->assertJsonPath('data.background_type', 'image');

        // Removing the pattern never changes the background mode.
        $this->deleteJson("/api/v1/appearance/pattern?center_id={$this->centerA}")
            ->assertOk()
            ->assertJsonPath('data.background_type', 'image');

        $this->deleteJson("/api/v1/appearance/background?center_id={$this->centerA}")
            ->assertOk()
            ->assertJsonPath('data.background_type', 'none')
            ->assertJsonPath('data.background_url', null);
    }

    public function test_background_engine_validation_rejects_unsafe_values(): void
    {
        $this->actingAs(User::findOrFail(1), 'api');

        $cases = [
            ['background_type' => 'video'],
            ['background_color' => 'red'],
            ['background_color' => '#12345'],
            ['gradient_type' => 'square'],
            ['gradient_angle' => 361],
            ['gradient_from' => 'not-a-color'],
            ['gradient_to' => '#12345g'],
            ['background_size' => '100'],
            ['background_size' => '10px 20px 30px'],
            ['background_position' => 'middle'],
            ['background_position' => 'right 20px bottom 10px'],
            ['background_repeat' => 'flip'],
            ['background_attachment' => 'sticky'],
            ['background_blend_mode' => 'glow'],
            ['pattern_enabled' => 'yes'],
            ['pattern_tint' => 'white'],
            ['pattern_invert' => 'yes'],
            ['backdrop_blur' => 41],
            ['glass_tint' => '#zzzzzz'],
            ['glass_opacity' => 101],
            ['orb_enabled' => 'yes'],
            ['orb_primary' => 'red'],
            ['orb_secondary' => '#12345g'],
            ['orb_opacity' => 101],
            ['scrim_enabled' => 'yes'],
            ['scrim_color' => 'white'],
            ['scrim_color' => '#12345g'],
            ['scrim_opacity' => 101],
        ];

        foreach ($cases as $payload) {
            $this->patchJson('/api/v1/appearance', $payload + ['center_id' => $this->centerA])
                ->assertStatus(422)
                ->assertJsonValidationErrors(array_key_first($payload));
        }

        $this->assertNull(AppearanceSetting::forCenter($this->centerA));
    }

    public function test_control_validation_rejects_out_of_range_and_unknown_values(): void
    {
        $this->actingAs(User::findOrFail(1), 'api');

        $cases = [
            ['background_opacity' => 101],
            ['background_opacity' => -1],
            ['background_size' => 'zoom'],
            ['background_position' => 'middle'],
            ['pattern_opacity' => 101],
            ['pattern_size' => 31],
            ['pattern_size' => 801],
        ];

        foreach ($cases as $payload) {
            $this->patchJson('/api/v1/appearance', $payload + ['center_id' => $this->centerA])
                ->assertStatus(422)
                ->assertJsonValidationErrors(array_key_first($payload));
        }

        $this->assertNull(AppearanceSetting::forCenter($this->centerA));

        $this->patchJson('/api/v1/appearance', [
            'center_id' => $this->centerA,
            'pattern_size' => 200,
        ])->assertOk();
        $setting = AppearanceSetting::forCenter($this->centerA);
        $this->assertSame(100, $setting->background_opacity);
        $this->assertSame('cover', $setting->background_size);
        $this->assertSame(16, $setting->pattern_opacity);
        $this->assertSame(200, $setting->pattern_size);
    }

    public function test_non_admin_cannot_update_controls(): void
    {
        $this->actingAs(User::findOrFail(1), 'api');
        $this->patchJson('/api/v1/appearance', [
            'center_id' => $this->centerA,
            'pattern_size' => 200,
        ])->assertOk();

        $this->actingAs(User::findOrFail(2), 'api');
        $this->patchJson('/api/v1/appearance', [
            'center_id' => $this->centerA,
            'pattern_size' => 400,
        ])->assertForbidden();

        $this->assertSame(200, AppearanceSetting::forCenter($this->centerA)->pattern_size);
    }

    private function upload(?int $centerId, string $kind, string $name)
    {
        $file = UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG));
        $payload = ['image' => $file];
        if ($centerId !== null) {
            $payload['center_id'] = $centerId;
        }

        return $this->post(
            "/api/v1/appearance/{$kind}",
            $payload,
            ['Accept' => 'application/json'],
        );
    }
}
