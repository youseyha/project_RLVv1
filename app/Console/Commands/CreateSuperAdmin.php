<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateSuperAdmin extends Command
{
    /**
     * ════════════════════════════════════════════════════════════
     * CREATE SUPER ADMIN COMMAND
     * ════════════════════════════════════════════════════════════
     * 
     * Usage:
     * php artisan make:super-admin
     * php artisan make:super-admin --email=admin@platform.com --password=secret
     */
    protected $signature = 'make:super-admin 
                            {--email= : Super admin email}
                            {--password= : Super admin password}
                            {--username= : Super admin username}
                            {--name= : Super admin full name}';

    protected $description = 'Create a super admin user for platform management';

    public function handle()
    {
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('🔐 SUPER ADMIN CREATION');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->newLine();

        // GET INPUT
        $email = $this->option('email') ?: $this->ask('Super Admin Email');
        $password = $this->option('password') ?: $this->secret('Super Admin Password (min 8 characters)');
        $username = $this->option('username') ?: $this->ask('Username', 'superadmin');

        // VALIDATE
        $validator = Validator::make([
            'email' => $email,
            'password' => $password,
            'username' => $username,
        ], [
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:8',
            'username' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            $this->error(' Validation failed:');
            foreach ($validator->errors()->all() as $error) {
                $this->line("  • {$error}");
            }
            return 1;
        }

        // CONFIRM
        $this->table(
            ['Field', 'Value'],
            [
                ['Email', $email],
                ['Username', $username],
                ['Role', 'super_admin'],
            ]
        );

        if (!$this->confirm('Create this super admin user?', true)) {
            $this->warn('Cancelled.');
            return 0;
        }

        try {
            // CREATE USER
            $user = User::create([
                'user_id' => (string) Str::uuid(),
                'tenant_id' => null, // Super admin has NO tenant
                'branch_id' => null,
                'username' => $username,
                'email' => $email,
                'password' => Hash::make($password),
                'is_active' => true,
            ]);
            
            //  ASSIGN SUPER_ADMIN ROLE
            $user->assignRole('super_admin');

            // SUCCESS MESSAGE
            $this->newLine();
            $this->info(' Super Admin created successfully!');
            $this->newLine();

            $this->table(
                ['Field', 'Value'],
                [
                    ['User ID', $user->user_id],
                    ['Email', $user->email],
                    ['Username', $user->username],
                    ['Role', 'super_admin'],
                    ['Tenant ID', $user->tenant_id ?? 'NULL (Platform Level)'],
                    ['Permissions', $user->getAllPermissions()->pluck('name')->join(', ')],
                ]
            );

            $this->newLine();
            $this->info('🔑 Login Credentials:');
            $this->line("  Email: {$email}");
            $this->line("  Password: [hidden]");
            $this->newLine();

            return 0;

        } catch (\Exception $e) {
            $this->error(' Failed to create super admin: ' . $e->getMessage());
            return 1;
        }
    }
}