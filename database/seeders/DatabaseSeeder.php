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
        $this->call(SubscriptionPlanSeeder::class);
        $this->call(IndustrySeeder::class);

        // Default dev account: admin@velora.test / password. It is also the
        // platform administrator, so `/admin` (tenant directory, monitoring,
        // support inbox) is reachable without creating a business. Re-running
        // `db:seed` refreshes the account instead of failing on the unique
        // email, which is how an existing workspace gets the flag.
        User::updateOrCreate(
            ['email' => 'admin@velora.test'],
            [
                'name' => 'Kendra Admin',
                'password' => 'password',
                'status' => 'active',
                'is_platform_admin' => true,
            ]
        );
    }
}
