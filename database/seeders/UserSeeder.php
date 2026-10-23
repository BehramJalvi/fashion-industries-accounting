<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    /**
     * Creates the first Admin and Accountant if they do not exist.
     * Set ADMIN_EMAIL / ADMIN_PASSWORD / ACCOUNTANT_EMAIL / ACCOUNTANT_PASSWORD in .env, or a random password
     * is generated and printed ONCE here. No default password is ever hard-coded.
     */
    public function run(): void
    {
        $this->seedUser('Administrator', env('ADMIN_EMAIL', 'admin@fashion-industries.local'), env('ADMIN_PASSWORD'), 'admin');
        $this->seedUser('Accountant', env('ACCOUNTANT_EMAIL', 'accountant@fashion-industries.local'), env('ACCOUNTANT_PASSWORD'), 'accountant');
    }

    private function seedUser(string $name, string $email, ?string $password, string $role): void
    {
        if (User::where('email', $email)->exists()) {
            return;
        }

        $generated = $password ? null : Str::password(16, symbols: false);

        $user = User::create(['name' => $name, 'email' => $email, 'password' => $password ?: $generated, 'is_active' => true]);
        $user->roles()->attach(Role::where('name', $role)->firstOrFail());

        $this->command?->info("Created {$role}: {$email}");
        if ($generated) {
            $this->command?->warn("  Generated password (shown once): {$generated}");
        }
    }
}
