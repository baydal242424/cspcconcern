<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $students_role = Role::where('name', 'Student')->first();
        $staff_role = Role::where('name', 'Faculty/Staff')->first();
        $counselor_role = Role::where('name', 'Guidance Counselor')->first();
        $admin_role = Role::where('name', 'Admin')->first();
        $head_role = Role::where('name', 'Head of School')->first();
        $depthead_role = Role::where('name', 'Department Head')->first();

        // Demo students
        User::firstOrCreate(
            ['email' => 'student@cspc.edu'],
            [
                'name' => 'John Student',
                'password' => Hash::make('password'),
                'role_id' => $students_role->id,
                'department' => 'General',
            ]
        );

        User::firstOrCreate(
            ['email' => 'student2@cspc.edu'],
            [
                'name' => 'Maria Santos',
                'password' => Hash::make('password'),
                'role_id' => $students_role->id,
                'department' => 'General',
            ]
        );

        // Demo staff
        User::firstOrCreate(
            ['email' => 'staff@cspc.edu'],
            [
                'name' => 'Prof. Juan Dela Cruz',
                'password' => Hash::make('password'),
                'role_id' => $staff_role->id,
                'department' => 'Academic Affairs',
            ]
        );

        // Demo counselor
        User::firstOrCreate(
            ['email' => 'counselor@cspc.edu'],
            [
                'name' => 'Dr. Maria Reyes',
                'password' => Hash::make('password'),
                'role_id' => $counselor_role->id,
                'department' => 'Guidance Office',
            ]
        );

        // Demo admin
        User::firstOrCreate(
            ['email' => 'admin@cspc.edu'],
            [
                'name' => 'Admin User',
                'password' => Hash::make('password'),
                'role_id' => $admin_role->id,
                'department' => 'Administration',
            ]
        );

        // Demo Head of School (break-glass authority)
        if ($head_role) {
            User::firstOrCreate(
                ['email' => 'head@cspc.edu'],
                [
                    'name' => 'Dr. Elena Villanueva',
                    'password' => Hash::make('password'),
                    'role_id' => $head_role->id,
                    'department' => 'Office of the President',
                ]
            );
        }

        // Demo Department Head (receives escalations and referrals)
        if ($depthead_role) {
            User::firstOrCreate(
                ['email' => 'depthead@cspc.edu'],
                [
                    'name' => 'Prof. Ramon Bautista',
                    'password' => Hash::make('password'),
                    'role_id' => $depthead_role->id,
                    'department' => 'College of Computer Studies',
                ]
            );
        }
    }
}