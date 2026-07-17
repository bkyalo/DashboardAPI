<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Switch inventory.reports to web guard (matching admin/manager/supervisor roles)
 * and assign it to all roles that should access inventory reports.
 */
return new class extends Migration
{
    // Roles that may view inventory movement reports
    private const REPORT_ROLES = ['admin', 'manager', 'supervisor'];

    public function up(): void
    {
        app()['cache']->forget('spatie.permission.cache');

        // Remove the api-guard version created by the previous migration
        Permission::where('name', 'inventory.reports')->where('guard_name', 'api')->delete();

        // Create / ensure web-guard version exists
        $perm = Permission::firstOrCreate(
            ['name' => 'inventory.reports', 'guard_name' => 'web'],
            ['description' => 'View inventory movement and stock reports']
        );

        // Assign to web-guard roles
        foreach (self::REPORT_ROLES as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
            if ($role) {
                $role->permissions()->syncWithoutDetaching([$perm->id]);
            }
        }

        app()['cache']->forget('spatie.permission.cache');
    }

    public function down(): void
    {
        app()['cache']->forget('spatie.permission.cache');
        Permission::where('name', 'inventory.reports')->delete();
        app()['cache']->forget('spatie.permission.cache');
    }
};
