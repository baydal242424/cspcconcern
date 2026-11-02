<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Concern;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Proof on resolving: offered, and never demanded.
 *
 * "Resolved" with a photograph of the repair behind it is better than
 * "resolved" alone, so the field is there and what is attached reaches the
 * student. It is not required, because most concerns do not end in
 * something photographable -- a grade query settled by a conversation, a
 * counselling case closed by agreement -- and refusing to let those resolve
 * leaves them open on the queue, which helps nobody. The written notes are
 * the record there.
 */
class ResolvingTakesProofTest extends TestCase
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

    private function aConcern(array $overrides = []): Concern
    {
        return Concern::create(array_merge([
            'user_id' => User::where('email', 'student@my.cspc.edu.ph')->firstOrFail()->id,
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
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'investigation_notes' => 'Looked into this and spoke with the people involved.',
            'resolution_notes' => 'The unit was repaired on Tuesday.',
            'status' => 'resolved',
            'urgency' => 'Low',
        ], $overrides);
    }

    /** Nothing attached still resolves. Not every outcome has a file. */
    public function test_resolving_without_proof_is_allowed(): void
    {
        $concern = $this->aConcern();

        $this->actingAs($this->handler())->from("/concerns/{$concern->id}")
            ->patch("/concerns/{$concern->id}", $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertSame('resolved', $concern->fresh()->status, 'it must have resolved');

        fwrite(STDERR, "  [proof] resolving with nothing attached is allowed\n");
    }

    /** With it, the file is stored and marked as proof. */
    public function test_proof_is_stored_against_the_concern(): void
    {
        $concern = $this->aConcern();

        $this->actingAs($this->handler())->patch("/concerns/{$concern->id}", $this->payload([
            'resolution_attachments' => [UploadedFile::fake()->create('repaired.jpg', 60, 'image/jpeg')],
        ]))->assertSessionHasNoErrors();

        $concern->refresh();

        $this->assertSame('resolved', $concern->status);
        $this->assertSame(1, $concern->resolutionProof()->count());
        $this->assertSame(0, $concern->evidence()->count(), 'it is proof, not the student\'s evidence');

        $proof = $concern->resolutionProof()->first();

        $this->assertSame('repaired.jpg', $proof->original_name);
        $this->assertSame($this->handler()->id, $proof->uploaded_by);

        // Private disk, random name -- same handling as a student's evidence.
        Storage::disk('local')->assertExists($proof->stored_path);
        $this->assertStringNotContainsString('repaired.jpg', $proof->stored_path);

        fwrite(STDERR, "  [proof] the file is stored privately and marked as proof\n");
    }

    /** The student sees it. That is the point of asking for it. */
    public function test_the_student_sees_the_proof(): void
    {
        $concern = $this->aConcern();

        $this->actingAs($this->handler())->patch("/concerns/{$concern->id}", $this->payload([
            'resolution_attachments' => [UploadedFile::fake()->create('repaired.jpg', 60, 'image/jpeg')],
        ]))->assertRedirect();

        $this->flushSession();

        $this->actingAs(User::where('email', 'student@my.cspc.edu.ph')->firstOrFail())
            ->get("/concerns/{$concern->id}")->assertOk()
            ->assertSee('Proof of Resolution')
            ->assertSee('repaired.jpg');

        fwrite(STDERR, "  [proof] the student sees it\n");
    }

    /** A settled case is not asked for the receipt a second time. */
    public function test_an_already_resolved_concern_is_not_asked_again(): void
    {
        $concern = $this->aConcern(['status' => 'resolved', 'resolved_at' => now()->subDay()]);

        // Still editable by an admin, who may be fixing the wording.
        $admin = User::where('email', 'admin@cspc.edu.ph')->firstOrFail();

        $this->actingAs($admin)->patch("/concerns/{$concern->id}", $this->payload([
            'resolution_notes' => 'Corrected a typo in the note above.',
        ]))->assertSessionHasNoErrors();

        fwrite(STDERR, "  [proof] an already resolved concern is not asked again\n");
    }

    /** Closing without action keeps its own rule, and is not asked for proof. */
    public function test_closing_without_action_is_unchanged(): void
    {
        $concern = $this->aConcern();

        $this->actingAs($this->handler())->patch("/concerns/{$concern->id}", $this->payload([
            'status' => 'closed_no_action',
            'closure_reason' => 'The unit was replaced before this was filed, under a separate request.',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('closed_no_action', $concern->fresh()->status);

        fwrite(STDERR, "  [proof] closing without action is not asked for proof\n");
    }

    /** The field is on the form, ready for when it is wanted. */
    public function test_the_field_appears_for_an_unresolved_concern(): void
    {
        $open = $this->aConcern();

        $this->actingAs($this->handler())->get("/concerns/{$open->id}")->assertOk()
            ->assertSee('Proof this was dealt with')
            ->assertSee('enctype="multipart/form-data"', false)
            // Said on the label, not only enforced in the controller: a
            // field marked with an asterisk is one handlers stop to hunt
            // for a file they may not have.
            ->assertSee('(optional)');

        fwrite(STDERR, "  [proof] the field is on the update form, marked optional\n");
    }

    /**
     * But it starts hidden, whatever the concern's status.
     *
     * It used to start VISIBLE on anything not already resolved and rely
     * on the script to put it away. So a handler moving a case to "In
     * Progress" was shown "Proof this was dealt with *" -- a receipt
     * demanded for work that had only just started -- and anything that
     * stopped the script left it on screen for good.
     *
     * Hidden by default there is no such state: it appears when resolved
     * is chosen, and at no other time.
     */
    public function test_the_proof_field_starts_hidden(): void
    {
        foreach (['submitted', 'in_progress', 'referred'] as $status) {
            $concern = $this->aConcern(['status' => $status]);

            $page = $this->actingAs($this->handler())
                ->get("/concerns/{$concern->id}")->assertOk()->getContent();

            $this->assertMatchesRegularExpression(
                '/id="resolution-proof-group"[^>]*style="display:none;"/',
                $page,
                'the proof field is on screen for a concern that is '.$status
            );
        }

        fwrite(STDERR, "  [proof] the field starts hidden, whatever the status\n");
    }

    /** And the script only ever reveals it for "resolved". */
    public function test_only_resolving_reveals_the_field(): void
    {
        $page = $this->actingAs($this->handler())
            ->get('/concerns/'.$this->aConcern()->id)->assertOk()->getContent();

        $at = strpos($page, "getElementById('resolution-proof-group')");
        $this->assertNotFalse($at, 'nothing drives the proof field');

        $script = substr($page, strrpos(substr($page, 0, $at), '<script'),
            strpos($page, '</script>', $at) - strrpos(substr($page, 0, $at), '<script'));

        // The one condition that may show it.
        $this->assertStringContainsString(
            "(statusEl.value === 'resolved' && !ALREADY_RESOLVED) ? 'block' : 'none'",
            $script
        );

        fwrite(STDERR, "  [proof] only choosing resolved reveals it\n");
    }
}
