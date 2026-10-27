<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\Role;
use App\Models\Section;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Reproduces the exact case that looked broken on the dashboard: a student
 * names an instructor and asks to skip their adviser, the concern lands on the
 * Program Chair, and the chair tries to pass it to the Dean.
 *
 * The dashboard showed "Currently referred: 0" afterwards, which is either a
 * counter that lies or a referral that never happened. These separate the two.
 */
class ReferringToTheDeanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    private function person(string $name, string $email, string $role, array $extra = []): User
    {
        return User::create(array_merge([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('not-used'),
            'role_id' => Role::where('name', $role)->firstOrFail()->id,
            'status' => 'approved',
            'department' => 'College of Computer Studies',
        ], $extra));
    }

    /**
     * @return array{0: Concern, 1: User, 2: User}
     */
    private function theCase(): array
    {
        $chair = $this->person('The Chair', 'chair@cspc.edu.ph', 'Program Chair', [
            'course' => 'BS Information Systems',
        ]);

        $dean = $this->person('The Dean', 'dean@cspc.edu.ph', 'Dean');

        $instructor = $this->person('The Instructor', 'instructor@cspc.edu.ph', 'Instructor', [
            'course' => 'BS Information Systems',
        ]);

        $student = User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();
        $student->forceFill([
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Systems',
            'section' => '2B',
        ])->save();

        // An adviser exists for the class, so skipping them is a real choice.
        $adviser = $this->person('The Adviser', 'adviser@cspc.edu.ph', 'Instructor', [
            'course' => 'BS Information Systems',
        ]);

        $term = Section::currentTerm();
        Section::create([
            'course' => 'BS Information Systems',
            'section' => '2B',
            'school_year' => $term['school_year'],
            'semester' => $term['semester'],
            'adviser_id' => $adviser->id,
        ]);

        $this->actingAs($student->refresh())->post('/concerns', [
            'category' => 'Academic',
            'description' => 'A concern about a teacher, which I would rather my adviser did not read.',
            'about_staff_id' => [$instructor->id],
            'skip_adviser' => 1,
        ])->assertRedirect();

        return [Concern::latest('id')->firstOrFail(), $chair, $dean];
    }

    /** First: the concern really does land on the Program Chair. */
    public function test_the_concern_reaches_the_program_chair(): void
    {
        [$concern, $chair] = $this->theCase();

        $this->assertSame($chair->id, $concern->assigned_to);
        $this->assertTrue((bool) $concern->skip_adviser);
        $this->assertContains((int) $concern->about_staff_id, $concern->subjectIds());

        fwrite(STDERR, "  [refer] naming a teacher and skipping the adviser reaches the Program Chair\n");
    }

    /** Then: the chair can hand it to the Dean, and it is recorded. */
    public function test_the_chair_can_refer_it_to_the_dean(): void
    {
        [$concern, $chair, $dean] = $this->theCase();

        $response = $this->actingAs($chair)->put("/concerns/{$concern->id}", [
            'investigation_notes' => 'Looked into this and spoke with the people involved.',
            'resolution_notes' => 'Recorded what is being done about it.',
            'status' => 'referred',
            'referred_to' => 'Dean',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $concern->refresh();

        $this->assertSame('referred', $concern->status, 'The status should now read referred');
        $this->assertSame('Dean', $concern->referred_to, 'The office it went to should be recorded');

        // A dean holds it -- findHandler() picks the closest eligible one, and
        // which dean that is depends on the data, so the role is what matters.
        $this->assertSame(
            'Dean',
            optional(optional($concern->assignedUser)->role)->name,
            'A dean should now hold it'
        );
        $this->assertNotSame($chair->id, $concern->assigned_to, 'It should have left the chair');

        // And the dashboard's "Currently referred" counts exactly this.
        $this->assertSame(
            1,
            Concern::where('status', 'referred')->whereNotIn('status', Concern::TERMINAL_STATUSES)->count()
        );

        fwrite(STDERR, "  [refer] the Program Chair can refer it to the Dean, and it is recorded\n");
    }

    /**
     * The thing most likely to have happened: a referral is NOT what the
     * dashboard counts once the case is finished. Resolving it afterwards
     * takes it out of "currently referred", which is correct -- it is no
     * longer with another office, it is done.
     */
    public function test_resolving_it_afterwards_keeps_the_record_of_where_it_went(): void
    {
        [$concern, $chair] = $this->theCase();

        $this->actingAs($chair)->put("/concerns/{$concern->id}", [
            'investigation_notes' => 'Looked into this and spoke with the people involved.',
            'resolution_notes' => 'Recorded what is being done about it.',
            'status' => 'referred',
            'referred_to' => 'Dean',
        ])->assertRedirect();

        $this->assertSame(1, Concern::where('status', 'referred')->count());

        // Whoever ended up with it finishes it.
        $holder = User::findOrFail($concern->fresh()->assigned_to);

        $this->actingAs($holder)->put("/concerns/{$concern->id}", [
            'investigation_notes' => 'Looked into this and spoke with the people involved.',
            'resolution_notes' => 'Recorded what is being done about it.','status' => 'resolved','resolution_attachments'=>[\Illuminate\Http\UploadedFile::fake()->create('proof.jpg', 50, 'image/jpeg')]])->assertRedirect();

        // No longer waiting with another office...
        $this->assertSame(0, Concern::where('status', 'referred')->count());

        // ...but the system still knows where it went. This used to be erased
        // the moment the status changed, so a referral that had been settled
        // left no trace on the concern at all and the dashboard's breakdown by
        // office could only ever show cases still in flight.
        $this->assertSame('Dean', $concern->fresh()->referred_to);

        fwrite(STDERR, "  [refer] a settled referral still records which office handled it\n");
    }

    /**
     * Keeping referred_to must not keep anybody's access alive with it. Every
     * visibility rule that reads the column pairs it with "not finished", and
     * a finished concern cannot be edited by anyone.
     */
    public function test_keeping_the_record_does_not_keep_access(): void
    {
        [$concern, $chair] = $this->theCase();

        // Two of them. The referral lands on one, who then holds the
        // case and keeps it afterwards as their own history -- so the
        // colleague is the one who shows whether the OFFICE still has
        // sight of it, which is what referred_to grants and what this
        // test is about.
        $this->person('An Office', 'an.office@cspc.edu.ph', 'Faculty/Staff');
        $counsellor = $this->person('Their Colleague', 'their.colleague@cspc.edu.ph', 'Faculty/Staff');

        $this->actingAs($chair)->put("/concerns/{$concern->id}", [
            'investigation_notes' => 'Looked into this and spoke with the people involved.',
            'resolution_notes' => 'Recorded what is being done about it.',
            // A college office, not the Guidance Office. Destinations are
            // narrowed by category now and this is an ACADEMIC case, which
            // a counsellor has no part in.
            //
            // Which office hardly matters, but it cannot be one that sees
            // the case anyway: a Dean sees their whole college and the
            // VPAA sees every academic case, so with either of them the
            // second half of this test would pass whether the window
            // closed or not.
            'status' => 'referred',
            'referred_to' => 'Faculty/Staff',
        ])->assertRedirect();


        // While it is open, the office it was referred to can see it.
        $this->assertTrue(
            Concern::whereKey($concern->id)->visibleTo($counsellor)->exists(),
            'An open referral should be visible to the office it went to'
        );

        $holder = User::findOrFail($concern->fresh()->assigned_to);
        $this->actingAs($holder)->put("/concerns/{$concern->id}", [
            'investigation_notes' => 'Looked into this and spoke with the people involved.',
            'resolution_notes' => 'Recorded what is being done about it.','status' => 'resolved','resolution_attachments'=>[\Illuminate\Http\UploadedFile::fake()->create('proof.jpg', 50, 'image/jpeg')]])->assertRedirect();

        // Once finished, the standing window closes again even though the
        // column still names them.
        $this->assertSame('Faculty/Staff', $concern->fresh()->referred_to);
        $this->assertFalse(
            Concern::whereKey($concern->id)->visibleTo($counsellor)->exists(),
            'A finished referral must not stay in another office\'s list'
        );

        fwrite(STDERR, "  [refer] the record outlives the referral; the access does not\n");
    }
}
