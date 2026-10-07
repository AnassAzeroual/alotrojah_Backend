<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Bootstrap the very first admin. Guarded by email, so re-running is a
     * harmless no-op on databases that already have one. Dev password only —
     * rotate before any production use.
     */
    public function run(): void
    {
        if (User::where('email', 'admin@example.org')->doesntExist()) {
            User::create([
                'full_name' => 'Admin',
                'email' => 'admin@example.org',
                'password_hash' => Hash::make('password123'),
                'role' => 'admin',
                'center_id' => null,
                'teacher_type' => 'both',
                'is_active' => true,
            ]);
        }
    }
}
