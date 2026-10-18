<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Docs access: session login for active admins only (viewApiDocs gate on the
 * Scramble routes). APP_ENV=testing here, so RestrictedDocsAccess takes the
 * gate path — the same path production runs.
 */
class DocsLoginTest extends TestCase
{
    private const PASSWORD = 'docs-test-password';

    protected function setUp(): void
    {
        parent::setUp();
        // phpunit.xml forces SESSION_DRIVER=array, which cannot carry the
        // docs_admin_id flag from the login POST into the next request.
        $this->app['config']->set('session.driver', 'file');
        // Throttle is exercised implicitly across tests; reset the counter so
        // attempts in one test never starve the next.
        $this->withoutMiddleware(ThrottleRequests::class);
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_guest_docs_are_forbidden(): void
    {
        $this->get('/docs/api.json')->assertForbidden();
    }

    public function test_login_page_renders(): void
    {
        $this->get('/docs/login')
            ->assertOk()
            ->assertSee('AlOtrojah API docs');
    }

    public function test_wrong_password_is_rejected_without_detail(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->from('/docs/login')->post('/docs/login', [
            'email' => $admin->email,
            'password' => 'not-the-password',
        ])->assertSessionHasErrors('email');

        $this->get('/docs/api.json')->assertForbidden();
    }

    public function test_non_admin_account_is_rejected(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);

        $this->from('/docs/login')->post('/docs/login', [
            'email' => $teacher->email,
            'password' => self::PASSWORD,
        ])->assertSessionHasErrors('email');

        $this->get('/docs/api.json')->assertForbidden();
    }

    public function test_deactivated_admin_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => false]);

        $this->from('/docs/login')->post('/docs/login', [
            'email' => $admin->email,
            'password' => self::PASSWORD,
        ])->assertSessionHasErrors('email');

        $this->get('/docs/api.json')->assertForbidden();
    }

    public function test_active_admin_logs_in_and_reaches_docs(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'password_hash' => \Illuminate\Support\Facades\Hash::make(self::PASSWORD),
        ]);

        $this->post('/docs/login', [
            'email' => $admin->email,
            'password' => self::PASSWORD,
        ])->assertRedirect('/docs/api');

        $this->get('/docs/api.json')->assertOk();
    }

    public function test_logout_revokes_docs_access(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'password_hash' => \Illuminate\Support\Facades\Hash::make(self::PASSWORD),
        ]);

        $this->post('/docs/login', [
            'email' => $admin->email,
            'password' => self::PASSWORD,
        ]);
        $this->get('/docs/api.json')->assertOk();

        $this->post('/docs/logout')->assertRedirect('/docs/login');
        $this->get('/docs/api.json')->assertForbidden();
    }
}
