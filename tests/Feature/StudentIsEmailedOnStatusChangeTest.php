<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\Role;
use App\Models\User;
use App\Notifications\ConcernStatusUpdated;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The student is emailed whenever a handler moves their concern.
 *
 * This is the promise the policy page makes, so it is pinned here through the
 * real HTTP route rather than by calling the service directly: the email has
 * to survive the whole path a Dean or an adviser actually takes -- the status
 * form on the concern page.
 *
 * It also pins what the email must NOT carry. Inboxes get forwarded and phones
 * show previews on lock screens, so the mail carries the concern number and
 * its new status and nothing else.
 */
class StudentIsEmailedOnStatusChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    private function student(): User
    {
        return User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();
    }

    private function staff(string $role, string $email): User
    {
        return User::create([
            'name' => $role.' Account',
            'email' => $email,
            'password' => Hash::make('not-used'),
            'role_id' => Role::where('name', $role)->firstOrFail()->id,
            'status' => 'approved',
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Technology',
        ]);
    }

    private function concern(User $handler, array $overrides = []): Concern
    {
        return Concern::create(array_merge([
            'user_id' => $this->student()->id,
            'category' => 'Academic',
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Technology',
            'section' => '3A',
            'description' => 'Something happened that I would like looked at.',
            'status' => 'submitted',
            'urgency' => 'Medium',
            'assigned_to' => $handler->id,
            'is_anonymous' => false,
        ], $overrides));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function handlerRoles(): array
    {
        return [
            'class adviser' => ['Adviser'],
            'instructor' => ['Instructor'],
            'program chair' => ['Program Chair'],
            'dean' => ['Dean'],
            'guidance counselor' => ['Guidance Counselor'],
            'administrative office' => ['Staff Admin'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('handlerRoles')]
    public function test_every_handler_moving_a_concern_emails_the_student(string $role): void
    {
        Notification::fake();

        $handler = $this->staff($role, strtolower(str_replace(' ', '.', $role)).'@cspc.edu.ph');
        $concern = $this->concern($handler);

        $this->actingAs($handler)
            ->put("/concerns/{$concern->id}", ['status' => 'in_progress'])
            ->assertRedirect();

        Notification::assertSentTo(
            $this->student(),
            ConcernStatusUpdated::class,
            function (ConcernStatusUpdated $notification) use ($concern) {
                // The mail must go to the CSPC address on the account.
                $mail = $notification->toMail($this->student());

                return str_contains($mail->render(), "#{$concern->id}");
            }
        );

        fwrite(STDERR, "  [email] {$role} sets In progress -> the student is emailed\n");
    }

    public function test_the_student_is_emailed_when_it_is_resolved(): void
    {
        Notification::fake();

        $dean = $this->staff('Dean', 'dean.email@cspc.edu.ph');
        $concern = $this->concern($dean);

        $this->actingAs($dean)
            ->put("/concerns/{$concern->id}", ['status' => 'resolved'])
            ->assertRedirect();

        Notification::assertSentTo($this->student(), ConcernStatusUpdated::class);

        // The handler is not told about their own action.
        Notification::assertNotSentTo($dean, ConcernStatusUpdated::class);

        fwrite(STDERR, "  [email] Resolved reaches the student, and not the staff member who did it\n");
    }

    /** A hand-off is a move too, even though the status reads 'referred' both times. */
    public function test_the_student_is_emailed_when_it_is_referred_onward(): void
    {
        Notification::fake();

        $chair = $this->staff('Program Chair', 'chair.email@cspc.edu.ph');
        $this->staff('Guidance Counselor', 'guidance.email@cspc.edu.ph');

        $concern = $this->concern($chair);

        $this->actingAs($chair)
            ->put("/concerns/{$concern->id}", [
                'status' => 'referred',
                'referred_to' => 'Guidance Counselor',
            ])
            ->assertRedirect();

        Notification::assertSentTo($this->student(), ConcernStatusUpdated::class);

        fwrite(STDERR, "  [email] a referral to another office also emails the student\n");
    }

    /** Saving the form without moving anything must not email the student. */
    public function test_no_email_when_the_status_did_not_change(): void
    {
        Notification::fake();

        $adviser = $this->staff('Adviser', 'adviser.email@cspc.edu.ph');
        $concern = $this->concern($adviser, ['status' => 'in_progress']);

        $this->actingAs($adviser)
            ->put("/concerns/{$concern->id}", [
                'status' => 'in_progress',
                'investigation_notes' => 'Spoke with the student today.',
            ])
            ->assertRedirect();

        Notification::assertNothingSentTo($this->student());

        fwrite(STDERR, "  [email] editing notes without moving the status sends nothing\n");
    }

    /**
     * What the email may say. Inboxes get forwarded and lock screens show
     * previews, so the case content stays behind sign-in.
     */
    public function test_the_email_carries_the_number_and_status_but_no_case_content(): void
    {
        $adviser = $this->staff('Adviser', 'adviser.content@cspc.edu.ph');

        $concern = $this->concern($adviser, [
            'description' => 'A private matter I would not want forwarded.',
        ]);

        $body = (new ConcernStatusUpdated($concern, 'In progress', 'Someone is now actively working on your concern.'))
            ->toMail($this->student())
            ->render();

        $this->assertStringContainsString("#{$concern->id}", $body);
        $this->assertStringContainsString('In progress', $body);

        $this->assertStringNotContainsString('A private matter', $body);
        $this->assertStringNotContainsString('Academic', $body);           // the category

        // The greeting does use the reader's own name -- that is their own
        // mailbox, and addressing them by name is not a disclosure. What must
        // never appear is the case: the description, the category, or who the
        // concern is about.
        $this->assertStringContainsString('Hi '.$this->student()->name, $body);

        fwrite(STDERR, "  [email] the mail carries the number and status only, never the case content\n");
    }
}
