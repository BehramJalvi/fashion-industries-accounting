<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    /** Safe to re-run: adds new permissions, never resets an Accountant role you have customised. */
    public function run(): void
    {
        foreach (Permissions::all() as $name) {
            Permission::firstOrCreate(['name' => $name], ['group' => explode('.', $name)[0]]);
        }

        $admin = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $admin->permissions()->sync(Permission::pluck('id'));        // display only; the Gate already passes admin everywhere

        $accountant = Role::firstOrCreate(['name' => 'accountant'], ['label' => 'Accountant']);
        if ($accountant->wasRecentlyCreated) {
            $accountant->permissions()->sync(Permission::whereIn('name', Permissions::accountantDefaults())->pluck('id'));
        }
    }
}
