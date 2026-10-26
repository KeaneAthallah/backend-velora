<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Default dev account: admin@velora.test / password
        User::factory()->create([
            'name' => 'Kendra Admin',
            'email' => 'admin@velora.test',
            'password' => 'password',
            'status' => 'active',
        ]);
    }
}
