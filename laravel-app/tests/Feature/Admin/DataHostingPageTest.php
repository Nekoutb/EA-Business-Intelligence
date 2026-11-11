<?php

namespace Tests\Feature\Admin;

use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantDatabase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Admin → client → Data hosting page: guards and the quick (in-request) steps. */
class DataHostingPageTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::create([
            'tenant_id' => null, 'name' => 'SA', 'email' => 'sa-' . Str::random(8) . '@example.test',
            'password' => Hash::make('x'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
        $this->tenant = Tenant::create(['slug' => 'dh-' . strtolower(Str::random(6)), 'name' => 'DH Test', 'status' => Tenant::STATUS_ACTIVE]);
        $this->withSession(['admin_user_id' => $admin->id]);
        $this->actingAs($admin);
    }

    private function url(string $p = ''): string
    {
        return '/admin/tenants/' . $this->tenant->id . '/data-hosting' . $p;
    }

    public function test_page_renders_for_a_shared_client(): void
    {
        $this->get($this->url())->assertOk()->assertSee('Data hosting')->assertSee('EA server · shared database')
             ->assertSee(TenantDatabase::defaultDatabaseName($this->tenant));
        $this->get('/admin/tenants/' . $this->tenant->id)->assertOk()->assertSee('Data hosting · EA shared');
        $this->getJson($this->url('/log'))->assertOk()->assertJson(['running' => false]);
    }

    public function test_save_client_target_encrypts_password_and_never_shows_it(): void
    {
        $this->post($this->url(), ['target' => 'client', 'host' => 'db.client.test', 'port' => 3307,
            'database' => 'acme_bms', 'username' => 'bms', 'password' => 'S3cret-pw!'])->assertRedirect();
        $t = $this->tenant->fresh();
        $this->assertSame('client', $t->db_config['target']);
        $this->assertSame('S3cret-pw!', $t->db_config['password']);
        $this->assertSame('shared', TenantDatabase::location($t), 'saving must not move the client');
        $raw = \DB::table('tenants')->where('id', $t->id)->value('db_config');
        $this->assertStringNotContainsString('S3cret', (string) $raw);
        $this->get($this->url())->assertOk()->assertDontSee('S3cret-pw!')->assertSee('db.client.test');

        // Blank password keeps the stored one.
        $this->post($this->url(), ['target' => 'client', 'host' => 'db2.client.test', 'port' => 3306,
            'database' => 'acme_bms', 'username' => 'bms', 'password' => ''])->assertRedirect();
        $this->assertSame('S3cret-pw!', $this->tenant->fresh()->db_config['password']);
    }

    public function test_client_target_requires_host_user_and_database(): void
    {
        $this->post($this->url(), ['target' => 'client'])->assertSessionHasErrors(['host', 'username']);
        $this->post($this->url(), ['target' => 'client', 'host' => 'h', 'username' => 'u'])->assertSessionHasErrors(['database']);
        $this->post($this->url(), ['target' => 'ea', 'database' => 'bad name; drop'])->assertSessionHasErrors(['database']);
    }

    public function test_switch_needs_the_slug_and_a_saved_target(): void
    {
        $this->post($this->url('/run/switch'), ['confirm_slug' => $this->tenant->slug])->assertSessionHas('flash.type', 'error');
        $this->post($this->url(), ['target' => 'ea'])->assertRedirect();
        $this->post($this->url('/run/switch'), ['confirm_slug' => 'wrong'])->assertSessionHas('flash.msg', 'Type the client slug to confirm the switch.');
        $this->assertSame('shared', TenantDatabase::location($this->tenant->fresh()));
        $this->post($this->url('/run/nonsense'))->assertNotFound();
    }

    public function test_switch_back_to_shared_from_the_page(): void
    {
        $this->tenant->forceFill(['data_location' => 'ea', 'db_config' => ['target' => 'ea']])->save();
        $this->post($this->url('/run/switch-back'), ['confirm_slug' => 'nope'])->assertSessionHas('flash.type', 'error');
        $this->assertSame('ea', $this->tenant->fresh()->data_location);
        $this->post($this->url('/run/switch-back'), ['confirm_slug' => $this->tenant->slug])->assertSessionHas('flash.type', 'success');
        $this->assertSame('shared', $this->tenant->fresh()->data_location);
        // Target can't be repointed while live elsewhere.
        $this->tenant->fresh()->forceFill(['data_location' => 'ea'])->save();
        $this->post($this->url(), ['target' => 'client', 'host' => 'h', 'username' => 'u', 'database' => 'd'])->assertSessionHas('flash.type', 'error');
        $this->assertSame('ea', $this->tenant->fresh()->db_config['target']);
    }

    public function test_pages_need_an_admin(): void
    {
        $this->flushSession();
        auth()->logout();
        $this->get($this->url())->assertRedirect();
    }
}
