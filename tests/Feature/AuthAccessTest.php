<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function userWithRole(string $role, bool $active = true): User
    {
        $user = User::factory()->create(['is_active' => $active]);
        $user->roles()->attach(Role::where('name', $role)->first());

        return $user;
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_admin_can_log_in_and_is_audited(): void
    {
        $admin = $this->userWithRole('admin');

        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($admin);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $admin->id, 'event' => 'login']);
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $user = $this->userWithRole('accountant', active: false);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_is_throttled_after_five_failures(): void
    {
        $user = $this->userWithRole('accountant');

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
        }

        // Even the correct password is refused while locked out.
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_accountant_can_see_dashboard_but_not_administration(): void
    {
        $accountant = $this->userWithRole('accountant');

        $this->actingAs($accountant)->get('/dashboard')->assertOk();
        $this->actingAs($accountant)->get('/users')->assertForbidden();
        $this->actingAs($accountant)->get('/roles')->assertForbidden();
        $this->actingAs($accountant)->get('/audit-logs')->assertForbidden();
    }

    public function test_accountant_has_no_cancel_permissions_but_can_post_sales(): void
    {
        $accountant = $this->userWithRole('accountant');

        $this->assertTrue($accountant->can('sales.post'));
        $this->assertFalse($accountant->can('sales.cancel'));
        $this->assertFalse($accountant->can('journal.create'));
        $this->assertFalse($accountant->can('owner_transactions.create') && $accountant->can('owner_transactions.post'));
    }

    public function test_admin_sees_administration_pages(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)->get('/users')->assertOk();
        $this->actingAs($admin)->get('/roles')->assertOk();
        $this->actingAs($admin)->get('/audit-logs')->assertOk();
    }

    public function test_last_active_admin_cannot_be_deactivated(): void
    {
        $admin = $this->userWithRole('admin');
        $manager = $this->userWithRole('accountant');
        $manager->roles->first()->permissions()->attach(Permission::where('name', 'users.manage')->first());

        $this->actingAs($manager->fresh())->patch("/users/{$admin->id}/toggle")->assertSessionHas('error');

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_user_cannot_deactivate_themselves(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)->patch("/users/{$admin->id}/toggle")->assertForbidden();
    }

    public function test_admin_role_permissions_are_not_editable(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)->put('/roles/' . Role::where('name', 'admin')->value('id'), ['permissions' => []])->assertForbidden();
    }

    public function test_weak_passwords_are_rejected_when_creating_a_user(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)->post('/users', [
            'name' => 'Test', 'email' => 'new@example.com', 'password' => 'short1', 'password_confirmation' => 'short1',
            'roles' => [Role::where('name', 'accountant')->value('id')],
        ])->assertSessionHasErrors('password');
    }

    public function test_model_changes_are_written_to_the_audit_log(): void
    {
        $admin = $this->userWithRole('admin');
        $this->actingAs($admin);

        $customer = Customer::create(['code' => 'C001', 'name' => 'Multan Fabrics PVT Limited']);
        $customer->update(['phone' => '0300-0000000']);

        $this->assertDatabaseHas('audit_logs', ['event' => 'created', 'auditable_type' => 'customer', 'auditable_id' => $customer->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'updated', 'auditable_type' => 'customer', 'auditable_id' => $customer->id]);
        $this->assertSame(0, AuditLog::where('new_values', 'like', '%password%')->count());
    }
}
