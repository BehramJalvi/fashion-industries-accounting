<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A brand-new installation (roles only, no accounts, no data) must still open the dashboard. */
class DashboardEmptyDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dashboard_opens_with_nothing_in_the_database(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->actingAs($admin)->get('/dashboard')->assertOk()->assertSee('Welcome')->assertSee('No invoices yet.')->assertSee('No drafts waiting');
    }
}
