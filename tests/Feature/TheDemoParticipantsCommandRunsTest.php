<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DemoRoleSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * demo:participants, run end to end.
 *
 * It had no test, and it broke without anybody noticing: a method was cut
 * out along with the code around it, and the command died on its last line
 * -- on the deployed site, where it is the one step the demonstration
 * depends on. These run the whole command, the way the person preparing
 * the demonstration does.
 */
class TheDemoParticipantsCommandRunsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class, DemoRoleSeeder::class]);
        Setting::forget();
    }

    private function realHolderOf(string $role): User
    {
        return User::whereHas('role', fn ($q) => $q->where('name', $role))
            ->where('email', 'not like', 'demo.%')
            ->where('email', 'not like', 'test.%')
            ->firstOrFail();
    }

    /** It runs to the end, and every routed office is held by a demo account only. */
    public function test_it_runs_and_steps_the_real_holders_aside(): void
    {
        $counsellor = $this->realHolderOf('Guidance Counselor');

        $this->assertSame(0, Artisan::call('demo:participants'), Artisan::output());

        $this->assertNull($counsellor->fresh()->role_id, 'The real counsellor steps aside');

        foreach (['Guidance Counselor', 'Program Chair', 'Dean', 'Clinic', 'General Services'] as $office) {
            $holders = User::whereHas('role', fn ($q) => $q->where('name', $office))->pluck('email');

            $this->assertNotEmpty($holders, "{$office} must still have somebody");

            foreach ($holders as $email) {
                $this->assertMatchesRegularExpression('/^(demo|test)\./', $email, "{$office} is held by {$email}");
            }
        }

        fwrite(STDERR, "  [demo participants] runs to the end; every routed office is a demo desk\n");
    }

    /** The people running the demonstration keep their roles. */
    public function test_the_administrators_keep_their_roles(): void
    {
        $admin = $this->realHolderOf('System Admin');

        Artisan::call('demo:participants');

        $this->assertSame('System Admin', $admin->fresh()->role->name);

        fwrite(STDERR, "  [demo participants] the System Admin keeps the role\n");
    }

    /** --clear gives every real person exactly their own role back. */
    public function test_clear_gives_every_role_back_exactly(): void
    {
        $before = User::pluck('role_id', 'id')->all();

        Artisan::call('demo:participants');
        // Twice: a second run must not record "no role" as somebody's own.
        Artisan::call('demo:participants');

        $this->assertSame(0, Artisan::call('demo:participants', ['--clear' => true]), Artisan::output());

        $after = User::whereIn('id', array_keys($before))->pluck('role_id', 'id')->all();

        $this->assertSame([], array_keys(array_diff_assoc($before, $after)), 'Every role is back as it was');
        $this->assertFalse(Setting::where('key', 'demo_parked_roles')->exists());

        fwrite(STDERR, "  [demo participants] --clear restores every role exactly, even after two runs\n");
    }

    /** An office with no demo account keeps its real holder. */
    public function test_an_office_with_no_demo_account_keeps_its_real_holder(): void
    {
        User::where('email', 'demo.nurse@cspc.edu.ph')->forceDelete();
        $nurse = $this->realHolderOf('Clinic');

        Artisan::call('demo:participants');

        $this->assertSame('Clinic', $nurse->fresh()->role->name, 'The clinic must not be left with nobody');

        fwrite(STDERR, "  [demo participants] no demo clinic, so the real one keeps the desk\n");
    }
}
