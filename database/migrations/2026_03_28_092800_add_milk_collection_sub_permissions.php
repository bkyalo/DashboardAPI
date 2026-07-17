<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        app()['cache']->forget('spatie.permission.cache');

        $newPerms = [
            'milk_collection.store'  => 'View store performance',
            'milk_collection.farmer' => 'View farmer milk supplier',
            'milk_collection.grader' => 'View grader milk collection',
            'milk_collection.view'   => 'View milk collection',
            'milk_collection.create' => 'Create milk collection records',
            'milk_collection.edit'   => 'Edit milk collection records',
            'milk_collection.export' => 'Export milk collection data',
        ];

        foreach ($newPerms as $name => $description) {
            // Some permissions may already exist with guard_name 'web' from PermissionSeeder.
            // Update to 'api' guard so they are returned for API-authenticated users.
            Permission::updateOrCreate(
                ['name' => $name],
                ['guard_name' => 'api', 'description' => $description]
            );
        }

        $permIds = Permission::whereIn('name', array_keys($newPerms))
            ->pluck('id')
            ->toArray();

        foreach (['super_admin', 'admin'] as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'api')->first();
            if ($role) {
                $role->permissions()->syncWithoutDetaching($permIds);
            }
        }

        app()['cache']->forget('spatie.permission.cache');
    }

    public function down(): void
    {
        app()['cache']->forget('spatie.permission.cache');

        // Revert guard_name back to 'web' (original state from PermissionSeeder)
        Permission::whereIn('name', [
            'milk_collection.store',
            'milk_collection.farmer',
            'milk_collection.grader',
        ])->update(['guard_name' => 'web']);

        app()['cache']->forget('spatie.permission.cache');
    }
};
