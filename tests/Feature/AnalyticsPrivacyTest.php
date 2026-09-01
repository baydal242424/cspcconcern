<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Concern;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;

class AnalyticsPrivacyTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void { parent::setUp(); $this->seed([RoleSeeder::class, UserSeeder::class]); }
    private function u(string $e): User { return User::where('email',$e)->firstOrFail(); }

    /** ANALYTICS: resolved concerns stay counted (numbers don't shrink) */
    public function test_analytics_count_includes_resolved(): void
    {
        $student = $this->u('student@my.cspc.edu.ph');
        // 3 academic concerns, all resolved
        for ($i=0; $i<3; $i++) {
            Concern::create(['user_id'=>$student->id,'category'=>'Academic','department'=>'College of Computer Studies',
                'description'=>'x','urgency'=>'Low','status'=>'resolved','is_anonymous'=>false]);
        }
        $admin = $this->u('admin@cspc.edu.ph');
        $resp = $this->actingAs($admin)->get('/dashboard');
        $resp->assertOk();
        // all-time Academic total should be 3 even though all resolved
        $totals = $resp->viewData('categoryTotals');
        fwrite(STDERR, "\n  [analytics] all-time Academic total (all resolved): ".($totals['Academic'] ?? 0)."\n");
        $this->assertEquals(3, $totals['Academic'] ?? 0, 'Resolved concerns must stay counted');
    }

    /** PRIVACY: anonymous concern shows 'Anonymous' to a staff viewer on dashboard */
    public function test_dashboard_recent_hides_anonymous_from_staff(): void
    {
        $staff = $this->u('staff@cspc.edu.ph');
        Concern::create(['user_id'=>$this->u('student@my.cspc.edu.ph')->id,'category'=>'Academic',
            'department'=>'College of Computer Studies','description'=>'x','urgency'=>null,
            'status'=>'submitted','is_anonymous'=>true,'assigned_to'=>$staff->id]);
        $resp = $this->actingAs($staff)->get('/dashboard');
        $resp->assertOk();
        $leak = str_contains($resp->getContent(), 'John Student');
        fwrite(STDERR, "  [privacy] staff sees anon submitter name: ".($leak?'YES (LEAK)':'NO')."\n");
        $this->assertFalse($leak);
        $resp->assertSee('Anonymous');
    }

    /** PRIVACY: the owner DOES see their own name on an anonymous concern */
    public function test_owner_sees_own_name_on_anonymous(): void
    {
        $student = $this->u('student@my.cspc.edu.ph');
        Concern::create(['user_id'=>$student->id,'category'=>'Academic',
            'department'=>'College of Computer Studies','description'=>'x','urgency'=>null,
            'status'=>'submitted','is_anonymous'=>true]);
        $resp = $this->actingAs($student)->get('/dashboard');
        $resp->assertOk();
        $sees = str_contains($resp->getContent(), 'John Student');
        fwrite(STDERR, "  [privacy] owner sees own name on their anon concern: ".($sees?'YES':'NO')."\n");
        $this->assertTrue($sees, 'Owner should see their own name even when anonymous');
    }

    /** FLASH: referring shows 'Referred successfully to X' */
    public function test_referral_flash_message(): void
    {
        $staff = $this->u('staff@cspc.edu.ph');
        $c = Concern::create(['user_id'=>$this->u('student@my.cspc.edu.ph')->id,'category'=>'Academic',
            'department'=>'College of Computer Studies','description'=>'x','urgency'=>null,
            'status'=>'submitted','is_anonymous'=>false,'assigned_to'=>$staff->id]);
        $resp = $this->actingAs($staff)->patch("/concerns/{$c->id}", [
            'status'=>'referred','referred_to'=>'Guidance Counselor','urgency'=>'Low',
        ]);
        $resp->assertRedirect();
        // The message names the person who actually received it, not just the
        // destination role -- with several deans, "Dean" alone hid
        // which one got the case.
        $this->assertStringContainsString('Referred successfully to Dr. Maria Reyes', session('success'));
        $this->assertStringContainsString('Guidance Counselor', session('success'));
        fwrite(STDERR, "  [flash] refer msg: '".session('success')."'\n");
    }

    /** FLASH: updating status (not referred) shows 'Concern updated successfully' */
    public function test_update_flash_message(): void
    {
        $staff = $this->u('staff@cspc.edu.ph');
        $c = Concern::create(['user_id'=>$this->u('student@my.cspc.edu.ph')->id,'category'=>'Academic',
            'department'=>'College of Computer Studies','description'=>'x','urgency'=>null,
            'status'=>'submitted','is_anonymous'=>false,'assigned_to'=>$staff->id]);
        $resp = $this->actingAs($staff)->patch("/concerns/{$c->id}", [
            'status'=>'in_progress','urgency'=>'Medium',
        ]);
        $resp->assertRedirect();
        $this->assertStringContainsString('Concern updated successfully', session('success'));
        fwrite(STDERR, "  [flash] update msg: '".session('success')."'\n");
    }
}