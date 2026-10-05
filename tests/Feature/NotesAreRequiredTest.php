<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A status cannot move without both notes.
 *
 * A badge that changes with nothing written beside it leaves the student
 * watching a word change colour, and leaves the next handler guessing what was
 * already looked into. The browser blocks an empty box before the request
 * leaves; this is the half that cannot be skipped by posting directly.
 */
class NotesAreRequiredTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    private function handler(): User
    {
        return User::create([
            'name' => 'The Handler',
            'email' => 'handler@cspc.edu.ph',
            'password' => Hash::make('not-used'),
            'role_id' => Role::where('name', 'Instructor')->firstOrFail()->id,
            'status' => 'approved',
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Technology',
        ]);
    }

    private function concern(User $handler): Concern
    {
        return Concern::create([
            'user_id' => User::where('email', 'student@my.cspc.edu.ph')->firstOrFail()->id,
            'category' => 'Academic',
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Technology',
            'description' => 'A concern that somebody has to write about.',
            'status' => 'submitted',
            'urgency' => 'Low',
            'assigned_to' => $handler->id,
            'is_anonymous' => false,
        ]);
    }

    public function test_neither_box_may_be_left_empty(): void
    {
        $handler = $this->handler();
        $concern = $this->concern($handler);

        $this->actingAs($handler)
            ->put("/concerns/{$concern->id}", ['status' => 'in_progress'])
            ->assertSessionHasErrors(['investigation_notes', 'resolution_notes']);

        $this->assertSame('submitted', $concern->fresh()->status, 'nothing should have moved');

        fwrite(STDERR, "  [notes] a status change with neither note is refused\n");
    }

    public function test_one_box_is_not_enough(): void
    {
        $handler = $this->handler();
        $concern = $this->concern($handler);

        $this->actingAs($handler)
            ->put("/concerns/{$concern->id}", [
                'status' => 'in_progress',
                'investigation_notes' => 'Spoke to the student this morning.',
            ])
            ->assertSessionHasErrors('resolution_notes')
            ->assertSessionDoesntHaveErrors('investigation_notes');

        $this->assertSame('submitted', $concern->fresh()->status);

        fwrite(STDERR, "  [notes] filling one box and not the other is still refused\n");
    }

    /** Spaces are not writing. TrimStrings turns them into an empty box. */
    public function test_whitespace_does_not_count_as_filled(): void
    {
        $handler = $this->handler();
        $concern = $this->concern($handler);

        $this->actingAs($handler)
            ->put("/concerns/{$concern->id}", [
                'status' => 'in_progress',
                'investigation_notes' => '     ',
                'resolution_notes' => "\t\n ",
            ])
            ->assertSessionHasErrors(['investigation_notes', 'resolution_notes']);

        $this->assertSame('submitted', $concern->fresh()->status);

        fwrite(STDERR, "  [notes] a box of spaces is an empty box\n");
    }

    /** It applies to every status, not only to resolving. */
    public function test_it_applies_to_every_status(): void
    {
        $handler = $this->handler();

        foreach (['in_progress', 'resolved', 'closed_no_action'] as $status) {
            $concern = $this->concern($handler);

            $this->actingAs($handler)
                ->put("/concerns/{$concern->id}", array_filter([
                    'status' => $status,
                    // Closing without action needs its own reason, which is a
                    // separate rule; supplied so the notes are what fails.
                    'closure_reason' => $status === 'closed_no_action'
                        ? 'Nothing further can be done, and the student has been told why.'
                        : null,
                ]))
                ->assertSessionHasErrors(['investigation_notes', 'resolution_notes']);

            $this->assertSame('submitted', $concern->fresh()->status, "{$status} should not have been saved");
        }

        fwrite(STDERR, "  [notes] every status needs both, not only Resolved\n");
    }

    /** With both filled, the save goes through as before. */
    public function test_with_both_filled_it_saves(): void
    {
        $handler = $this->handler();
        $concern = $this->concern($handler);

        $this->actingAs($handler)
            ->put("/concerns/{$concern->id}", [
                'status' => 'in_progress',
                'investigation_notes' => 'Spoke to the student and to the subject teacher.',
                'resolution_notes' => 'Arranging a meeting this week.',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $concern->refresh();

        $this->assertSame('in_progress', $concern->status);
        $this->assertSame('Arranging a meeting this week.', $concern->resolution_notes);

        fwrite(STDERR, "  [notes] with both written, the update saves\n");
    }

    /** The form itself blocks it too, so the error is rare rather than normal. */
    public function test_the_form_marks_both_as_required(): void
    {
        $handler = $this->handler();
        $concern = $this->concern($handler);

        $page = $this->actingAs($handler)->get("/concerns/{$concern->id}")->assertOk();

        $page->assertSee('name="investigation_notes" id="investigation_notes" required', false)
            ->assertSee('name="resolution_notes" id="resolution_notes" required', false)
            ->assertSee('The student reads both of these.', false);

        fwrite(STDERR, "  [notes] the form marks both required, so the browser stops an empty save\n");
    }
}
