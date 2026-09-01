<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every college a student can register under needs a Dean to
 * receive its escalations and referrals, so the seeder has to cover the
 * whole list -- not just Computer Studies.
 */
class DepartmentHeadSeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    public function test_every_college_has_a_department_head(): void
    {
        $heads = User::whereHas('role', fn ($q) => $q->where('name', 'Dean'))
            ->pluck('department')
            ->all();

        foreach (array_keys(User::COURSES_BY_COLLEGE) as $college) {
            $this->assertContains($college, $heads, "No Dean seeded for {$college}");
        }
    }

    public function test_computer_studies_head_is_seeded_first(): void
    {
        // routeConcern() escalates to the first Dean it finds, and
        // the referral tests assume that stays this account.
        $first = User::whereHas('role', fn ($q) => $q->where('name', 'Dean'))->first();

        $this->assertSame('ccs@cspc.edu.ph', $first->email);
        $this->assertSame('College of Computer Studies', $first->department);
    }
}
