<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run(): void
    {
        // A ordem importa: roles devem existir antes dos users (FK)
        $this->call([
            RoleSeeder::class,
            UserSeeder::class,
            ServiceOrderStatusSeeder::class,
            CustomerSeeder::class,
        ]);
    }
}
