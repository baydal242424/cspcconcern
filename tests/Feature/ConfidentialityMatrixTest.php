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
 * Who can read an untouched concern, by role and category.
 *
 * "Confidential" is a claim, and a claim about visibility is only worth what
 * can be demonstrated. This walks every role against every category and pins
 * the whole matrix, so widening one rule fails here rather than being noticed
 * by a student whose report was read by the wrong office.
 *
 * Untouched is the case that matters: nothing assigned, nothing referred,
 * nobody involved. Assignment and referral are deliberate acts by somebody who
 * could already see it. What this measures is what a role can reach with no
 * such act -- the standing view.
 */
class ConfidentialityMatrixTest extends TestCase
{
    use RefreshDatabase;

    /** Categories only the Guidance Office may read without being sent one. */
    private const CONFIDENTIAL = ['Mental Health', 'Personal', 'Bullying', 'Harassment'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    private function holderOf(string $roleName): User
    {
        $existing = User::whereHas('role', fn ($q) => $q->where('name', $roleName))->first();

        return $existing ?? User::create([
            'name' => "Probe {$roleName}",
            'email' => 'probe.'.Str()->slug($roleName).'@cspc.edu.ph',
            'password' => Hash::make('not-used'),
            'role_id' => Role::where('name', $roleName)->firstOrFail()->id,
            'department' => 'Probe Unit',
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);
    }

    private function untouched(string $category): Concern
    {
        return Concern::create([
            'user_id' => User::where('email', 'student@my.cspc.edu.ph')->firstOrFail()->id,
            'category' => $category,
            'department' => 'College of Computer Studies',
            'description' => "An untouched {$category} concern, assigned to nobody.",
            'status' => 'submitted',
            'is_anonymous' => false,
            'assigned_to' => null,
        ]);
    }

    /**
     * The confidential categories are readable by the Guidance Office and by
     * nobody else -- including the roles that outrank it.
     */
    public function test_only_guidance_can_read_a_confidential_concern(): void
    {
        $roles = Role::where('name', '!=', 'Student')->pluck('name');

        foreach (self::CONFIDENTIAL as $category) {
            $concern = $this->untouched($category);

            foreach ($roles as $roleName) {
                $canSee = Concern::whereKey($concern->id)
                    ->visibleTo($this->holderOf($roleName))
                    ->exists();

                $shouldSee = in_array($roleName, ['Guidance Counselor', 'Head of School'], true);

                $this->assertSame(
                    $shouldSee,
                    $canSee,
                    "{$roleName} visibility of an untouched '{$category}' concern"
                );
            }

            fwrite(STDERR, "  [confidential] {$category}: Guidance + Head of School only\n");
        }
    }

    /** A student reads their own concerns and no one else's, ever. */
    public function test_a_student_sees_only_their_own(): void
    {
        $mine = $this->untouched('Academic');
        $other = Concern::create([
            'user_id' => User::where('email', 'student2@my.cspc.edu.ph')->firstOrFail()->id,
            'category' => 'Academic',
            'department' => 'College of Health Sciences',
            'description' => 'Filed by a different student entirely.',
            'status' => 'submitted',
            'is_anonymous' => false,
        ]);

        $me = User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();
        $visible = Concern::visibleTo($me)->pluck('id');

        $this->assertTrue($visible->contains($mine->id));
        $this->assertFalse($visible->contains($other->id));

        fwrite(STDERR, "  [student] own concerns only\n");
    }

    /**
     * The Head of School reads content but not identities, and the reveal is
     * a separate, logged act rather than a property of the role.
     */
    public function test_the_head_of_school_reads_content_but_not_names(): void
    {
        $head = $this->holderOf('Head of School');

        $concern = Concern::create([
            'user_id' => User::where('email', 'student@my.cspc.edu.ph')->firstOrFail()->id,
            'category' => 'Mental Health',
            'department' => 'College of Computer Studies',
            'description' => 'An anonymous report the Head of School may adjudicate.',
            'status' => 'submitted',
            'is_anonymous' => true,
        ]);

        $resp = $this->actingAs($head)->get("/concerns/{$concern->id}");
        $resp->assertOk();
        $resp->assertSee('An anonymous report the Head of School may adjudicate.');
        $resp->assertDontSee('John Student');

        fwrite(STDERR, "  [head] reads the content, not the reporter\n");
    }

    /**
     * The wall that outranks everything: the subject of a concern cannot read
     * it, whatever role they hold.
     */
    public function test_the_subject_can_never_read_it_whatever_their_rank(): void
    {
        foreach (Role::where('name', '!=', 'Student')->pluck('name') as $roleName) {
            $subject = $this->holderOf($roleName);

            $concern = Concern::create([
                'user_id' => User::where('email', 'student@my.cspc.edu.ph')->firstOrFail()->id,
                'category' => 'Academic',
                'department' => 'College of Computer Studies',
                'description' => "A concern about the {$roleName}.",
                'status' => 'submitted',
                'is_anonymous' => false,
                'about_staff_id' => $subject->id,
                // Even handed to them directly, which no code path does.
                'assigned_to' => $subject->id,
            ]);

            $this->assertFalse(
                Concern::whereKey($concern->id)->visibleTo($subject)->exists(),
                "{$roleName} must not read a concern about themselves"
            );
        }

        fwrite(STDERR, "  [wall] no role can read a concern about itself, assignment included\n");
    }

    /** Evidence files follow the concern, not the URL. */
    public function test_an_attachment_is_refused_to_someone_who_cannot_see_the_concern(): void
    {
        $concern = $this->untouched('Mental Health');

        $attachment = $concern->attachments()->create([
            'uploaded_by' => $concern->user_id,
            'original_name' => 'probe.pdf',
            'stored_path' => 'attachments/probe.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
        ]);

        $this->actingAs($this->holderOf('Instructor'))
            ->get("/concerns/{$concern->id}/attachments/{$attachment->id}")
            ->assertForbidden();

        fwrite(STDERR, "  [evidence] download refused to a role that cannot see the concern\n");
    }
}
