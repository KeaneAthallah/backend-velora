<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'businesses.view', 'businesses.manage',
            'outlets.view', 'outlets.manage',
            'employees.view', 'employees.manage',
            'devices.view', 'devices.manage',
            'products.view', 'products.manage',
            'inventory.view', 'inventory.adjust',
            'customers.view', 'customers.manage',
            'suppliers.view', 'suppliers.manage',
            'purchasing.view', 'purchasing.manage',
            'sales.view', 'sales.create', 'sales.void', 'sales.refund',
            'shifts.view', 'shifts.manage',
            'settings.manage',
            'reports.view',
            'platform.admin',
        ];
        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p], ['description' => $p]);
        }
    }
}
