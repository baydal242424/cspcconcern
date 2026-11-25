<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DemoRoleSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * While a demonstration is running, a concern lands on a demo desk.
 *
 * An office you cannot sign in to cannot answer. The real holders are the
 * right desks on a working site and the wrong ones on a stage, so this
 * moves where a concern LANDS -- and only while it is switched on, which
 * is not how any deploy arrives.
 *
 * The switch is its own row, not the sign-in one. Letting somebody sign in
 * and re-routing every student's report are two decisions, and the second
 * must never be made by accident while making the first. The first test
 * here is that one being off while the other is on.
 */
class ADemonstrationRoutesToItsOwnDesksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class, DemoRoleSeeder::class]);
        Setting::forget();
    }

    private function student(): User
    {
        return User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();
    }

    /**
     * File one, and prove a new one arrived.
     *
     * latest('id') on its own hands back the PREVIOUS concern when a post
     * does not go through, so a test reads the desk from a row filed under
     * the old setting and fails saying the switch does not work. It cost an
     * hour. The id gate below turns that into the truth: nothing was filed,
     * and here is what the application said about it.
     */
    private function fileAs(User $student, string $category, string $subcategory): Concern
    {
        $before = Concern::max('id') ?? 0;

        $response = $this->actingAs($student)->post('/concerns', [
            'category' => $category,
            'subcategory' => $subcategory,
            'description' => 'A concern filed to see which desk it reaches.',
            'is_anonymous' => 0,
        ]);

        $concern = Concern::where('id', '>', $before)->latest('id')->first();

        $this->assertNotNull($concern, sprintf(
            'Nothing was filed. HTTP %d%s',
            $response->getStatusCode(),
            session('errors') ? ' -- '.implode(' ', session('errors')->all()) : ''
        ));

        return $concern;
    }

    private function handlerOf(Concern $concern): ?User
    {
        return $concern->assigned_to ? User::find($concern->assigned_to) : null;
    }

    /**
     * Off by default, and the sign-in switch does not turn it on.
     *
     * This is the test that matters most. Somebody opening the dropdown for
     * a demonstration must not discover afterwards that every report filed
     * since went to a desk nobody was watching.
     */
    public function test_it_is_off_by_default_and_the_sign_in_switch_does_not_open_it(): void
    {
        $this->assertFalse(\App\Http\Controllers\ConcernController::demoDesksPreferred());

        Setting::putFlag(Setting::DEMO_LOGIN, true);
        Setting::forget();

        $this->assertFalse(
            \App\Http\Controllers\ConcernController::demoDesksPreferred(),
            'Turning the sign-in dropdown on must not re-route anybody'
        );

        $concern = $this->fileAs($this->student(), 'Facilities', 'Water');
        $handler = $this->handlerOf($concern);

        $this->assertNotNull($handler);
        $this->assertStringStartsNotWith('demo.', $handler->email, 'The real desk still takes it');

        fwrite(STDERR, "  [demo routing] off by default, and the sign-in switch leaves it off\n");
    }

    /** Switched on, the demo desk takes it. */
    public function test_switched_on_the_demo_desk_takes_it(): void
    {
        Setting::putFlag(Setting::DEMO_ROUTING, true);
        Setting::forget();

        $concern = $this->fileAs($this->student(), 'Facilities', 'Water');
        $handler = $this->handlerOf($concern);

        $this->assertNotNull($handler, 'Somebody has to receive it');
        $this->assertStringStartsWith('demo.', $handler->email);

        fwrite(STDERR, "  [demo routing] switched on, {$handler->name} takes it\n");
    }

    /**
     * An office with no demo account falls back to the real desk.
     *
     * Preferring a demo desk must never mean losing the concern when there
     * is not one. A report nobody holds is worse than a report held by the
     * wrong person: the second can be referred, the first is invisible.
     */
    public function test_an_office_with_no_demo_account_still_reaches_its_real_desk(): void
    {
        Setting::putFlag(Setting::DEMO_ROUTING, true);
        Setting::forget();

        // Take the demo clinic away, as a database that never seeded one has.
        User::where('email', 'demo.nurse@cspc.edu.ph')->forceDelete();

        $concern = $this->fileAs($this->student(), 'Physical', 'Classroom');
        $handler = $this->handlerOf($concern);

        $this->assertNotNull($handler, 'A missing demo desk must not strand the concern');
        $this->assertStringStartsNotWith('demo.', $handler->email);

        fwrite(STDERR, "  [demo routing] no demo clinic, so the real one still takes it: {$handler->name}\n");
    }

    /** And switching it back off returns every concern to the real desks. */
    public function test_switching_it_off_returns_the_concerns_to_the_real_desks(): void
    {
        Setting::putFlag(Setting::DEMO_ROUTING, true);
        Setting::forget();

        $onDemo = $this->handlerOf($this->fileAs($this->student(), 'Facilities', 'Water'));
        $this->assertStringStartsWith('demo.', $onDemo->email);

        Setting::putFlag(Setting::DEMO_ROUTING, false);
        Setting::forget();

        // A SECOND STUDENT files the second one.
        //
        // The same student filing the same thing again is read as a
        // duplicate: the application redirects them to the concern they
        // already have rather than opening another, which is right, and
        // meant this test was reading the desk off the row filed a moment
        // earlier under the old setting. Two students, two concerns, and
        // the only thing that differs between them is the switch.
        $other = User::whereHas('role', fn ($q) => $q->where('name', 'Student'))
            ->where('id', '!=', $this->student()->id)
            ->whereNotNull('course')
            ->firstOrFail();

        $afterwards = $this->handlerOf($this->fileAs($other, 'Facilities', 'Water'));

        $this->assertNotNull($afterwards);
        $this->assertStringStartsNotWith('demo.', $afterwards->email);

        fwrite(STDERR, "  [demo routing] switched off, the real desk has it back\n");
    }
}
