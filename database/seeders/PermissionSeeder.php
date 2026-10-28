<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
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
            'loyalty.view', 'loyalty.manage',
            'promotions.view', 'promotions.manage',
            'suppliers.view', 'suppliers.manage',
            'purchasing.view', 'purchasing.manage',
            'menu.view', 'menu.manage', 'menu.availability', 'menu.dayparts',
            'ingredients.view', 'ingredients.manage', 'recipes.view', 'recipes.manage',
            'orders.view', 'orders.create', 'orders.manage', 'orders.cancel',
            'tables.view', 'tables.manage',
            'kitchen.view', 'kitchen.manage', 'kitchen.print',
            'sales.view', 'sales.create', 'sales.void', 'sales.refund',
            'payments.view', 'payments.manage',
            'shifts.view', 'shifts.manage',
            'settings.manage',
            'reports.view',
            'platform.admin',
        ];
        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p], ['description' => $p]);
        }

        $ids = Permission::whereIn('name', $permissions)->pluck('id');
        Role::where('slug', 'owner')->get()->each(function ($role) use ($ids) {
            $role->permissions()->syncWithoutDetaching($ids);
        });
    }
}
