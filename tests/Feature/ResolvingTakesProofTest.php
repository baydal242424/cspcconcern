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
 * Marking a concern resolved takes something to show for it.
 *
 * "Resolved" with nothing behind it asks the student to take somebody's word
 * that the aircon was repaired or the grade corrected. A photograph of the
 * repair or a scan of the corrected record is the difference between saying
 * it was fixed and showing them.
 *
 * Asked on the move TO resolved only. Correcting a typo on an already
 * settled case does not ask for the receipt again.
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

    /** Without proof, it does not resolve. */
    public function test_resolving_without_proof_is_refused(): void
    {
        $concern = $this->aConcern();

        $this->actingAs($this->handler())->from("/concerns/{$concern->id}")
            ->patch("/concerns/{$concern->id}", $this->payload())
            ->assertSessionHasErrors('resolution_attachments');

        $this->assertSame('in_progress', $concern->fresh()->status, 'it must not have resolved');

        fwrite(STDERR, "  [proof] resolving with nothing attached is refused\n");
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

    /** The field is on the form, and only when it is wanted. */
    public function test_the_field_appears_for_an_unresolved_concern(): void
    {
        $open = $this->aConcern();

        $this->actingAs($this->handler())->get("/concerns/{$open->id}")->assertOk()
            ->assertSee('Proof this was dealt with')
            ->assertSee('enctype="multipart/form-data"', false);

        fwrite(STDERR, "  [proof] the field is on the update form\n");
    }
}
