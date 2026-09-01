<?php

namespace Database\Seeders;

use App\Models\User;
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
        $this->call([
            RoleSeeder::class,
            UserSeeder::class,
            // Real named people, after the roles and office mailboxes they
            // depend on. Kept in its own namespace so a person joining or
            // leaving is an edit to one file, not to the office roster.
            \Database\Seeders\Faculty\CcsFacultySeeder::class,
        ]);
    }
}
