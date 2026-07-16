<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            [
                'name' => 'Student',
                'description' => 'College student who can submit concerns',
            ],
            [
                'name' => 'Faculty/Staff',
                'description' => 'Faculty or staff member who can handle concerns',
            ],
            [
                'name' => 'Department Head',
                'description' => 'Department head/dean who manages concerns',
            ],
            [
                'name' => 'Guidance Counselor',
                'description' => 'Guidance office staff for mental health and referrals',
            ],
            [
                'name' => 'Admin',
                'description' => 'System administrator with full access',
            ],
            [
                'name' => 'Head of School',
                'description' => 'Highest authority; can read concerns and perform logged identity reveals (break-glass) for accountability',
            ],
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate(
                ['name' => $role['name']],
                ['description' => $role['description']]
            );
        }
    }
}