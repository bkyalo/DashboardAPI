<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        app()['cache']->forget('spatie.permission.cache');

        $name = 'milk_collection.farmer_supplier_statement';

        // Sibling milk_collection.* permissions (farmer/store/grader) all live
        // under guard 'web' and are held by the admin/manager roles — this
        // repo's actual auth setup, despite `add_milk_collection_sub_permissions`
        // aiming for guard 'api'. Match what's really there, not that migration.
        Permission::updateOrCreate(
            ['name' => $name],
            ['guard_name' => 'web', 'description' => 'View farmer supplier statement']
        );

        $permId = Permission::where('name', $name)->value('id');

        foreach (['admin', 'manager'] as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
            if ($role) {
                $role->permissions()->syncWithoutDetaching([$permId]);
            }
        }

        app()['cache']->forget('spatie.permission.cache');
    }

    public function down(): void
    {
        app()['cache']->forget('spatie.permission.cache');
        Permission::where('name', 'milk_collection.farmer_supplier_statement')->delete();
        app()['cache']->forget('spatie.permission.cache');
    }
};
