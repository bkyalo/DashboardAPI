<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        app()['cache']->forget('spatie.permission.cache');

        // Create the permission (api guard, matches what the backend returns for API users)
        Permission::updateOrCreate(
            ['name' => 'inventory.reports'],
            ['guard_name' => 'api', 'description' => 'View inventory movement and stock reports']
        );

        $perm = Permission::where('name', 'inventory.reports')->where('guard_name', 'api')->first();

        // Assign to roles that should access inventory reports
        $roles = ['super_admin', 'admin', 'inventory_manager', 'sales_manager', 'purchase_manager'];
        foreach ($roles as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'api')->first();
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
