<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The policy page is what a student reads before deciding what to write, so a
 * promise it makes has to be one the system keeps.
 *
 * It claimed anonymity long after anonymous submission was removed, and said
 * only the assigned handler could read a concern while each office sees its own
 * categories. These check the page against the behaviour it describes.
 */
class PolicyMatchesTheSystemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    /** Public on purpose: it is read before signing in, and before reporting. */
    public function test_anyone_can_read_it(): void
    {
        $this->get('/policy')->assertOk()->assertSee('Data Privacy');

        fwrite(STDERR, "  [policy] readable without signing in: YES\n");
    }

    public function test_it_does_not_promise_anonymity_the_system_does_not_offer(): void
    {
        $html = html_entity_decode($this->get('/policy')->assertOk()->getContent(), ENT_QUOTES);

        $this->assertStringContainsString('under your own name', $html);
        $this->assertStringContainsString('does not offer anonymous submission', $html);
        $this->assertStringNotContainsString('without your name being shown', $html);

        // And the system itself: a concern filed through the form is never
        // anonymous, whatever the form is asked for.
        $student = User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();
        $student->forceFill([
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Technology',
            'section' => '3A',
        ])->save();

        $this->actingAs($student->refresh())->post('/concerns', [
            'category' => 'Academic',
            'description' => 'A concern filed to check the policy is telling the truth.',
            'is_anonymous' => 1,
        ]);

        $this->assertFalse((bool) Concern::latest('id')->firstOrFail()->is_anonymous);

        fwrite(STDERR, "  [policy] says concerns are named, and they are: YES\n");
    }

    /** The routing and escalation the page describes are the ones in the code. */
    public function test_it_describes_the_routing_that_exists(): void
    {
        $html = html_entity_decode($this->get('/policy')->assertOk()->getContent(), ENT_QUOTES);

        foreach ([
            'class adviser',                       // first handler for four categories
            'Guidance Office',                     // counselling categories
            'General Services Unit',               // facilities and equipment
            'I do not want this to go to my class adviser',
            'Program Chair',                       // adviser bypass and escalation
            'VPAA',                                // a concern about a Dean
            'only the concern number and its status', // what emails carry
        ] as $promise) {
            $this->assertStringContainsString($promise, $html, "the policy should mention: {$promise}");
        }

        // The old text sent facility cases to the Administration and a concern
        // about a Dean sideways to another Dean.
        $this->assertStringNotContainsString('an Admin sees administrative and facility cases', $html);
        $this->assertStringNotContainsString('peer) authority', $html);

        fwrite(STDERR, "  [policy] adviser tier, offices, bypass and escalation described correctly: YES\n");
    }
}
