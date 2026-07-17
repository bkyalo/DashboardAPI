<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // roles.name is unique (not name+guard); keep guard aligned with the API
        $roles = [
            ['name' => 'admin', 'display_name' => 'Administrator', 'description' => 'Full system access'],
            ['name' => 'manager', 'display_name' => 'Manager', 'description' => 'Department manager with limited access'],
            ['name' => 'supervisor', 'display_name' => 'Supervisor', 'description' => 'Team supervisor'],
            ['name' => 'user', 'display_name' => 'User', 'description' => 'Regular system user'],
            ['name' => 'viewer', 'display_name' => 'Viewer', 'description' => 'Read-only access'],
            ['name' => 'guest', 'display_name' => 'Guest', 'description' => 'Limited guest access'],
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate(
                ['name' => $role['name']],
                [
                    'guard_name' => 'api',
                    'display_name' => $role['display_name'],
                    'description' => $role['description'],
                ]
            );
        }

        $this->command->info('✅ Roles created successfully');
    }
}
