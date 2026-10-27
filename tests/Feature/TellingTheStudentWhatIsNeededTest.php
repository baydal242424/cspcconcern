<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Concern;
use App\Models\Notification;
use App\Models\User;
use App\Notifications\ConcernNoticeFromHandler;
use App\Services\ConcernNotificationService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification as Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Reaching the student about their own concern.
 *
 * Until this existed a case could tell a student one thing -- its status --
 * and a status answers "is anything happening" and nothing else. A handler
 * who needed the medical certificate, or needed the student in the office
 * on Tuesday, had to reach them some other way: a text message, or asking
 * around their section. That left no record on the case, and for an
 * anonymous report there was nobody to ask.
 *
 * Two halves, and the second is what makes the first worth having. Asking
 * for a document is a dead end unless the student has somewhere to put it,
 * and files could previously only be attached at the moment of filing.
 */
class TellingTheStudentWhatIsNeededTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
        Storage::fake('local');
    }

    private function handler(): User
    {
        return User::where('email', 'staff@cspc.edu.ph')->firstOrFail();
    }

    private function student(): User
    {
        return User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();
    }

    private function aConcern(array $overrides = []): Concern
    {
        return Concern::create(array_merge([
            'user_id' => $this->student()->id,
            'category' => 'Facilities',
            'department' => 'College of Computer Studies',
            'description' => 'The aircon in Room 204 has not worked for two weeks.',
            'urgency' => 'Low',
            'status' => 'in_progress',
            'is_anonymous' => false,
            'assigned_to' => $this->handler()->id,
        ], $overrides));
    }

    /**
     * @return array<string, string>
     */
    private function notice(array $overrides = []): array
    {
        return array_merge([
            'kind' => 'documents',
            'message' => 'Please send the service request slip you filed with Maintenance.',
        ], $overrides);
    }

    // ---- The handler asking -------------------------------------------

    /** The student is notified, and the case records that they were. */
    public function test_a_handler_can_ask_the_student_for_a_document(): void
    {
        $concern = $this->aConcern();

        $this->actingAs($this->handler())
            ->post(route('concerns.notify', $concern), $this->notice())
            ->assertRedirect(route('concerns.show', $concern));

        $row = Notification::where('concern_id', $concern->id)
            ->where('type', ConcernNotificationService::TYPE_NOTICE_FROM_HANDLER)
            ->first();

        $this->assertNotNull($row, 'the student was not notified');
        $this->assertSame($this->student()->id, $row->user_id, 'somebody else was notified');
        $this->assertStringContainsString('service request slip', $row->message);

        // It signs itself. "Come and see me" from nobody in particular is
        // not something anybody can act on.
        $this->assertStringContainsString($this->handler()->name, $row->message);

        $this->assertDatabaseHas('audit_logs', [
            'concern_id' => $concern->id,
            'action' => 'reporter_notified',
        ]);

        fwrite(STDERR, "  [notice] a handler can ask the student for a document\n");
    }

    /** Each kind leads with what it is, because that is what says "do something". */
    public function test_the_kind_decides_the_heading(): void
    {
        foreach (['documents', 'meeting', 'update'] as $kind) {
            $concern = $this->aConcern();

            $this->actingAs($this->handler())
                ->post(route('concerns.notify', $concern), $this->notice(['kind' => $kind]));

            $row = Notification::where('concern_id', $concern->id)->latest('id')->first();

            $this->assertSame(Concern::noticeTitle($kind), $row->title);
        }

        // And the three are actually different headings, or the test above
        // would pass with one shared title.
        $this->assertCount(3, array_unique(array_map(
            fn ($kind) => Concern::noticeTitle($kind),
            ['documents', 'meeting', 'update']
        )));

        fwrite(STDERR, "  [notice] each kind leads with what it is\n");
    }

    /**
     * The handler's words stay behind the sign-in.
     *
     * "Bring the medical certificate from the 3rd" says what happened,
     * roughly when, and that somebody was hurt. A mailbox is forwardable,
     * readable on a shared machine and visible on a lock screen, so the
     * mail says a message is waiting and nothing about what it says.
     */
    public function test_the_email_carries_no_case_content(): void
    {
        Mail::fake();

        $concern = $this->aConcern();
        $secret = 'Bring the medical certificate dated the 3rd of March.';

        $this->actingAs($this->handler())
            ->post(route('concerns.notify', $concern), $this->notice(['message' => $secret]));

        Mail::assertSentTo($this->student(), ConcernNoticeFromHandler::class,
            function ($notification) use ($secret, $concern) {
                $mail = $notification->toMail($this->student());
                $body = $mail->subject.' '.implode(' ', array_merge(
                    $mail->introLines,
                    $mail->outroLines
                ));

                $this->assertStringNotContainsString($secret, $body, 'the message leaked into the email');
                $this->assertStringNotContainsString('medical', strtolower($body));
                $this->assertStringNotContainsString($concern->category, $body, 'the category leaked');
                $this->assertStringNotContainsString($concern->description, $body);

                return true;
            });

        fwrite(STDERR, "  [notice] the email says a message is waiting, not what it says\n");
    }

    /**
     * An anonymous report can be answered without unmasking anybody.
     *
     * This is the case that had no answer at all before: the handler could
     * not reach the student because they did not know who the student was.
     * The notice is addressed to the account, so it still works and the
     * handler still does not know.
     */
    public function test_an_anonymous_reporter_can_be_reached(): void
    {
        $concern = $this->aConcern(['is_anonymous' => true]);

        $response = $this->actingAs($this->handler())
            ->post(route('concerns.notify', $concern), $this->notice());

        $response->assertRedirect();

        $this->assertDatabaseHas('notifications', [
            'concern_id' => $concern->id,
            'user_id' => $this->student()->id,
            'type' => ConcernNotificationService::TYPE_NOTICE_FROM_HANDLER,
        ]);

        // Nothing in the page names them. The handler is writing blind,
        // which is the point.
        $page = $this->actingAs($this->handler())->get(route('concerns.show', $concern))->getContent();

        $this->assertStringNotContainsString($this->student()->name, $page,
            'the reporter was named on an anonymous concern');

        fwrite(STDERR, "  [notice] an anonymous reporter can be reached without being unmasked\n");
    }

    /** A message with nothing in it is the one that gets sent twice. */
    public function test_a_message_is_required(): void
    {
        $concern = $this->aConcern();

        $this->actingAs($this->handler())
            ->post(route('concerns.notify', $concern), $this->notice(['message' => 'send it']))
            ->assertSessionHasErrors('message');

        $this->assertDatabaseMissing('audit_logs', [
            'concern_id' => $concern->id,
            'action' => 'reporter_notified',
        ]);

        fwrite(STDERR, "  [notice] a message too short to act on is refused\n");
    }

    /** Somebody with no claim on the concern cannot write to its reporter. */
    public function test_an_unrelated_member_of_staff_is_refused(): void
    {
        $concern = $this->aConcern();

        $stranger = User::where('email', 'counselor@cspc.edu.ph')->firstOrFail();

        $this->actingAs($stranger)
            ->post(route('concerns.notify', $concern), $this->notice())
            ->assertForbidden();

        $this->assertSame(0, Notification::where('concern_id', $concern->id)
            ->where('type', ConcernNotificationService::TYPE_NOTICE_FROM_HANDLER)
            ->count());

        fwrite(STDERR, "  [notice] staff with no claim on the concern are refused\n");
    }

    /** And neither can the student write to themselves. */
    public function test_the_reporter_cannot_notify_themselves(): void
    {
        $concern = $this->aConcern();

        $this->actingAs($this->student())
            ->post(route('concerns.notify', $concern), $this->notice())
            ->assertForbidden();

        fwrite(STDERR, "  [notice] the reporter cannot write to their own case\n");
    }

    /** A finished case takes nothing further. */
    public function test_a_resolved_concern_accepts_no_notices(): void
    {
        $concern = $this->aConcern(['status' => 'resolved']);

        $this->actingAs($this->handler())
            ->post(route('concerns.notify', $concern), $this->notice())
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('audit_logs', [
            'concern_id' => $concern->id,
            'action' => 'reporter_notified',
        ]);

        fwrite(STDERR, "  [notice] a resolved concern accepts no further notices\n");
    }

    /** The student can read it on the concern, not only in the bell. */
    public function test_the_student_sees_the_notice_on_their_concern(): void
    {
        $concern = $this->aConcern();

        $this->actingAs($this->handler())
            ->post(route('concerns.notify', $concern), $this->notice());

        $page = $this->actingAs($this->student())
            ->get(route('concerns.show', $concern))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('service request slip', $page);

        fwrite(STDERR, "  [notice] the student can reread it on the concern\n");
    }

    /**
     * Two requests are two things to do.
     *
     * The student timeline collapses a run of hand-offs into one line,
     * because being passed along three times is one thing happening to
     * them. Being asked for two different documents is not.
     */
    public function test_two_requests_stay_two_lines(): void
    {
        $concern = $this->aConcern();

        $this->actingAs($this->handler())
            ->post(route('concerns.notify', $concern), $this->notice(['message' => 'Please send the service request slip.']));

        $this->actingAs($this->handler())
            ->post(route('concerns.notify', $concern), $this->notice(['message' => 'Also send the photograph you mentioned.']));

        $timeline = $concern->fresh()->load('auditLogs.user')->timelineFor($this->student());

        $labels = $timeline->pluck('label')->implode(' | ');

        $this->assertStringContainsString('service request slip', $labels);
        $this->assertStringContainsString('photograph', $labels);

        fwrite(STDERR, "  [notice] two requests stay two lines on the timeline\n");
    }

    // ---- The student answering ----------------------------------------

    /** The reply to "send me the document" is the document. */
    public function test_the_student_can_send_documents_afterwards(): void
    {
        $concern = $this->aConcern();

        $this->actingAs($this->student())
            ->post(route('concerns.documents', $concern), [
                'documents' => [UploadedFile::fake()->create('slip.pdf', 40)],
            ])
            ->assertRedirect(route('concerns.show', $concern));

        $file = Attachment::where('concern_id', $concern->id)->latest('id')->first();

        $this->assertNotNull($file, 'the file was not stored');
        $this->assertSame(Attachment::KIND_FOLLOW_UP, $file->kind,
            'a later document was filed as original evidence');
        $this->assertSame($this->student()->id, $file->uploaded_by);

        // Kept apart from the evidence filed with the concern, so a handler
        // can see whether what they asked for actually arrived.
        $this->assertCount(0, $concern->fresh()->evidence);
        $this->assertCount(1, $concern->fresh()->followUpDocuments);

        fwrite(STDERR, "  [notice] the student can send documents after filing\n");
    }

    /** The handler is told, or they have to keep reopening the case to look. */
    public function test_the_handler_is_told_the_documents_arrived(): void
    {
        $concern = $this->aConcern();

        $this->actingAs($this->student())
            ->post(route('concerns.documents', $concern), [
                'documents' => [
                    UploadedFile::fake()->create('slip.pdf', 20),
                    UploadedFile::fake()->create('photo.jpg', 20),
                ],
            ]);

        $row = Notification::where('concern_id', $concern->id)
            ->where('type', ConcernNotificationService::TYPE_DOCUMENTS_ADDED)
            ->first();

        $this->assertNotNull($row, 'the handler was not told');
        $this->assertSame($this->handler()->id, $row->user_id);
        $this->assertStringContainsString('2 files', $row->message);

        fwrite(STDERR, "  [notice] the handler is told when the documents arrive\n");
    }

    /** Nobody else can put files on somebody's concern. */
    public function test_another_student_cannot_add_documents(): void
    {
        $concern = $this->aConcern();

        $classmate = User::where('email', 'student2@my.cspc.edu.ph')->firstOrFail();

        $this->actingAs($classmate)
            ->post(route('concerns.documents', $concern), [
                'documents' => [UploadedFile::fake()->create('slip.pdf', 20)],
            ])
            ->assertForbidden();

        $this->assertCount(0, $concern->fresh()->followUpDocuments);

        fwrite(STDERR, "  [notice] another student cannot add to somebody's concern\n");
    }

    /** Not even the handler: these are the reporter's own files. */
    public function test_the_handler_cannot_add_documents_as_the_student(): void
    {
        $concern = $this->aConcern();

        $this->actingAs($this->handler())
            ->post(route('concerns.documents', $concern), [
                'documents' => [UploadedFile::fake()->create('slip.pdf', 20)],
            ])
            ->assertForbidden();

        fwrite(STDERR, "  [notice] the handler cannot file documents as the student\n");
    }

    /** A finished case takes no more files either. */
    public function test_a_resolved_concern_takes_no_more_documents(): void
    {
        $concern = $this->aConcern(['status' => 'resolved']);

        $this->actingAs($this->student())
            ->post(route('concerns.documents', $concern), [
                'documents' => [UploadedFile::fake()->create('slip.pdf', 20)],
            ])
            ->assertSessionHas('error');

        $this->assertCount(0, $concern->fresh()->followUpDocuments);

        fwrite(STDERR, "  [notice] a resolved concern takes no more documents\n");
    }

    /** The form is offered to the reporter, and the asking form is not. */
    public function test_each_side_is_offered_its_own_half(): void
    {
        $concern = $this->aConcern();

        $asStudent = $this->actingAs($this->student())
            ->get(route('concerns.show', $concern))->getContent();

        $this->assertStringContainsString(route('concerns.documents', $concern), $asStudent);
        $this->assertStringNotContainsString(route('concerns.notify', $concern), $asStudent,
            'the student was offered the form for writing to themselves');

        $asHandler = $this->actingAs($this->handler())
            ->get(route('concerns.show', $concern))->getContent();

        $this->assertStringContainsString(route('concerns.notify', $concern), $asHandler);
        $this->assertStringNotContainsString(route('concerns.documents', $concern), $asHandler,
            'the handler was offered the student\'s upload form');

        fwrite(STDERR, "  [notice] each side is offered its own half\n");
    }
}
