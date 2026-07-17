<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Seed roles first
        $this->call(RoleSeeder::class);

        // Then seed permissions
        $this->call(PermissionSeeder::class);

        // Finally seed users
        $this->call(UserSeeder::class);

        $this->command->info('');
        $this->command->info('✨ Database seeding completed successfully!');
    }
}
