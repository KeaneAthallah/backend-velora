<?php

namespace Database\Seeders;

use App\Services\IndustryService;
use Illuminate\Database\Seeder;

class IndustrySeeder extends Seeder
{
    public function run(): void
    {
        app(IndustryService::class)->ensureSeeded();
    }
}
