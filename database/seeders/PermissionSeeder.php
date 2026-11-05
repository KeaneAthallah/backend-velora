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
            'channels.view', 'channels.manage',
            'shifts.view', 'shifts.manage',
            'settings.manage',
            'reports.view',
            'platform.admin',
            // Finance
            'accounts.view', 'accounts.manage',
            'journal.view', 'journal.manage',
            'financial_reports.view',
            'financial_transactions.view', 'financial_transactions.manage',
            'ar.view', 'ar.manage',
            'ap.view', 'ap.manage',
            // HR
            'hr.view', 'hr.manage',
            'attendance.view', 'attendance.manage',
            'leave.view', 'leave.manage',
            'payroll.view', 'payroll.manage',
            // CRM
            'crm.view', 'crm.manage',
            // Inventory improvements
            'warehouse_zones.view', 'warehouse_zones.manage',
            'supplier_quotations.view', 'supplier_quotations.manage',
            // Industry-specific
            'services.view', 'services.manage',
            'appointments.view', 'appointments.create', 'appointments.manage',
            'price_lists.view', 'price_lists.manage',
            'warehouses.view', 'warehouses.manage',
            'units.view', 'units.manage',
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
