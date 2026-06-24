<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    /**
     * ════════════════════════════════════════════════════════════
     * ROLE & PERMISSION SEEDER for SaaS
     * ════════════════════════════════════════════════════════════
     * 
     * Run:
     * php artisan db:seed --class=RolePermissionSeeder
     * 
     * Or with fresh migration:
     * php artisan migrate:fresh --seed
     * 
     * Clear cache:
     * php artisan permission:cache-reset
     */
    public function run(): void
    {
        // ═══════════════════════════════════════════════════════
        // ① RESET CACHE
        // ═══════════════════════════════════════════════════════
        app()[\Spatie\Permission\PermissionRegistrar::class]
            ->forgetCachedPermissions();

        $this->command->info(' Clearing permission cache...');

        // ═══════════════════════════════════════════════════════
        // ② CREATE PERMISSIONS
        // ═══════════════════════════════════════════════════════
        $this->command->info(' Creating permissions...');

        $permissions = [
            // ═══════════════════════════════════════════════════
            // SYSTEM-LEVEL PERMISSIONS (Super Admin only)
            // ════════════════════════════════════════════════
            'system.manage-plans' => 'Manage subscription plans',
            'system.manage-tenants' => 'Manage all tenants',
            'system.view-analytics' => 'View system analytics',
            'system.manage-settings' => 'Manage platform settings',

            // ═══════════════════════════════════════════════════
            // TENANT-LEVEL PERMISSIONS
            // ═══════════════════════════════════════════════════
            
            // Users
            'users.view' => 'View users',
            'users.create' => 'Create users',
            'users.update' => 'Update users',
            'users.delete' => 'Delete users',
            'users.assign-roles' => 'Assign roles to users',

            // Products
            'products.view' => 'View products',
            'products.create' => 'Create products',
            'products.update' => 'Update products',
            'products.delete' => 'Delete products',

            // Categories
            'categories.view' => 'View categories',
            'categories.create' => 'Create categories',
            'categories.update' => 'Update categories',
            'categories.delete' => 'Delete categories',

            // Transactions
            'transactions.view' => 'View transactions',
            'transactions.create' => 'Create transactions',
            'transactions.refund' => 'Refund transactions',
            'transactions.cancel' => 'Cancel transactions',

            // Inventory
            'inventory.view' => 'View inventory',
            'inventory.manage' => 'Manage inventory',
            'inventory.adjust' => 'Adjust inventory',
            'inventory.transfer' => 'Transfer stock',

            // Branches
            'branches.view' => 'View branches',
            'branches.create' => 'Create branches',
            'branches.update' => 'Update branches',
            'branches.delete' => 'Delete branches',

            // Reports
            'reports.view' => 'View reports',
            'reports.export' => 'Export reports',
            'reports.financial' => 'View financial reports',

            // Settings
            'settings.view' => 'View settings',
            'settings.update' => 'Update settings',

            // Billing (Tenant level)
            'billing.view' => 'View billing',
            'billing.manage' => 'Manage billing',
        ];

        foreach ($permissions as $name => $description) {
            Permission::create([
                'name' => $name,
                'guard_name' => 'web',
            ]);
            $this->command->line("   {$name}");
        }

        // ═══════════════════════════════════════════════════════
        // ③ CREATE ROLES & ASSIGN PERMISSIONS
        // ═══════════════════════════════════════════════════════
        $this->command->info("\n👥 Creating roles...");

        // ───────────────────────────────────────────────────────
        // SUPER ADMIN (Platform Level) - តាម screenshot image 1
        // ───────────────────────────────────────────────────────
        $superAdmin = Role::create([
            'name' => 'super_admin',
            'guard_name' => 'web',
        ]);

        // ផ្តល់តែ system permissions
        $systemPermissions = Permission::where('name', 'like', 'system.%')->get();
        $superAdmin->givePermissionTo($systemPermissions);
        
        $this->command->info("   super_admin ({$systemPermissions->count()} system permissions)");

        // ───────────────────────────────────────────────────────
        // ADMIN (Tenant Level) - តាម screenshot image 2
        // ───────────────────────────────────────────────────────
        $admin = Role::create([
            'name' => 'admin',
            'guard_name' => 'web',
        ]);

        // ផ្តល់ tenant permissions ទាំងអស់ (គ្មាន system.*)
        $tenantPermissions = Permission::where('name', 'not like', 'system.%')->get();
        $admin->givePermissionTo($tenantPermissions);
        
        $this->command->info("   admin ({$tenantPermissions->count()} tenant permissions)");

        // ───────────────────────────────────────────────────────
        // MANAGER - តាម screenshot image 2
        // ───────────────────────────────────────────────────────
        $manager = Role::create([
            'name' => 'manager',
            'guard_name' => 'web',
        ]);

        $managerPermissions = [
            'users.view',
            'products.view', 'products.create', 'products.update', 'products.delete',
            'categories.view', 'categories.create', 'categories.update', 'categories.delete',
            'transactions.view', 'transactions.create', 'transactions.refund',
            'inventory.view', 'inventory.manage', 'inventory.adjust', 'inventory.transfer',
            'branches.view',
            'reports.view', 'reports.export',
            'settings.view',
        ];

        $manager->givePermissionTo($managerPermissions);
        $this->command->info("   manager (" . count($managerPermissions) . " permissions)");

        // ───────────────────────────────────────────────────────
        // CASHIER - POS only - តាម screenshot image 2
        // ───────────────────────────────────────────────────────
        $cashier = Role::create([
            'name' => 'cashier',
            'guard_name' => 'web',
        ]);

        $cashierPermissions = [
            'products.view',
            'categories.view',
            'transactions.view',
            'transactions.create',
            'inventory.view',
        ];

        $cashier->givePermissionTo($cashierPermissions);
        $this->command->info("   cashier (" . count($cashierPermissions) . " permissions)");

        // ───────────────────────────────────────────────────────
        // STAFF - Read only - តាម screenshot image 2
        // ───────────────────────────────────────────────────────
        $staff = Role::create([
            'name' => 'staff',
            'guard_name' => 'web',
        ]);

        $staffPermissions = [
            'products.view',
            'categories.view',
            'transactions.view',
            'inventory.view',
        ];

        $staff->givePermissionTo($staffPermissions);
        $this->command->info("   staff (" . count($staffPermissions) . " permissions)");

        // ═══════════════════════════════════════════════════════
        // SUMMARY
        // ═══════════════════════════════════════════════════════
        $this->command->newLine();
        $this->command->info(' Role & Permission seeding completed!');
        $this->command->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->table(
            ['Item', 'Count'],
            [
                ['Permissions', Permission::count()],
                ['Roles', Role::count()],
                ['Super Admin Permissions', $systemPermissions->count()],
                ['Tenant Permissions', $tenantPermissions->count()],
            ]
        );
        $this->command->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    }
}