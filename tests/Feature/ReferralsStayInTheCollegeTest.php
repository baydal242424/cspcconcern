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
 * A concern stays inside the student's own college all the way down the chain.
 *
 * The pickers used to hold every college's people, merely SORTED so the right
 * ones came first. A Computer Studies case therefore offered all seven deans,
 * and one mis-click handed a student's concern to a college with no connection
 * to them, the programme, or the people involved.
 */
class ReferralsStayInTheCollegeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    private function person(string $name, string $email, string $role, string $college, array $extra = []): User
    {
        return User::create(array_merge([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('not-used'),
            'role_id' => Role::where('name', $role)->firstOrFail()->id,
            'status' => 'approved',
            'department' => $college,
        ], $extra));
    }

    /**
     * A Computer Studies student files, skipping their adviser.
     *
     * @return array{0: Concern, 1: User}
     */
    private function aComputerStudiesCase(): array
    {
        $ccs = 'College of Computer Studies';

        $chair = $this->person('CCS Chair', 'ccs.chair@cspc.edu.ph', 'Program Chair', $ccs, [
            'course' => 'BS Information Systems',
        ]);

        // The seeded data already has a CCS dean (ccs@cspc.edu.ph); adding a
        // second would only test which of two the lookup prefers.
        $this->person('CCS Office Staff', 'ccs.staff@cspc.edu.ph', 'Faculty/Staff', $ccs);

        // The other colleges, all fully staffed -- none of them should ever
        // appear for this student.
        foreach (['College of Health Sciences' => 'chs', 'College of Engineering and Architecture' => 'cea'] as $college => $slug) {
            $this->person(ucfirst($slug).' Dean', $slug.'.dean@cspc.edu.ph', 'Dean', $college);
            $this->person(ucfirst($slug).' Chair', $slug.'.chair@cspc.edu.ph', 'Program Chair', $college);
            $this->person(ucfirst($slug).' Staff', $slug.'.staff@cspc.edu.ph', 'Faculty/Staff', $college);
        }

        $adviser = $this->person('Their Adviser', 'their.adviser@cspc.edu.ph', 'Instructor', $ccs, [
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

        $student = User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();
        $student->forceFill([
            'department' => $ccs,
            'course' => 'BS Information Systems',
            'section' => '2B',
        ])->save();

        $this->actingAs($student->refresh())->post('/concerns', [
            'category' => 'Academic',
            'description' => 'A concern I would rather my class adviser did not handle.',
            'skip_adviser' => 1,
        ])->assertRedirect();

        return [Concern::latest('id')->firstOrFail(), $chair];
    }

    /** Step one: skipping the adviser reaches the chair of their own programme. */
    public function test_skipping_the_adviser_reaches_their_own_program_chair(): void
    {
        [$concern, $chair] = $this->aComputerStudiesCase();

        $this->assertSame($chair->id, $concern->assigned_to);
        $this->assertSame('BS Information Systems', $chair->course);

        fwrite(STDERR, "  [college] skipping the adviser reaches the chair of the student's own programme\n");
    }

    /** Step two: the chair refers to "Dean" and gets their own college's dean. */
    public function test_a_referral_to_the_dean_reaches_their_own_colleges_dean(): void
    {
        [$concern, $chair] = $this->aComputerStudiesCase();

        $this->actingAs($chair)->put("/concerns/{$concern->id}", [
            'investigation_notes' => 'Looked into this and spoke with the people involved.',
            'resolution_notes' => 'Recorded what is being done about it.',
            'status' => 'referred',
            'referred_to' => 'Dean',
        ])->assertSessionHasNoErrors();

        $holder = User::findOrFail($concern->fresh()->assigned_to);

        $this->assertSame('ccs@cspc.edu.ph', $holder->email);
        $this->assertSame('College of Computer Studies', $holder->department);

        fwrite(STDERR, "  [college] referring to the Dean reaches the student's own college's dean\n");
    }

    /** And no other college's people are even offered. */
    public function test_the_picker_offers_nobody_from_another_college(): void
    {
        [$concern, $chair] = $this->aComputerStudiesCase();

        $page = $this->actingAs($chair)->get("/concerns/{$concern->id}")->assertOk();

        $offered = collect($page->viewData('referralCandidates'))->flatten();

        $this->assertTrue($offered->contains('email', 'ccs@cspc.edu.ph'), 'their own dean should be offered');
        $this->assertTrue($offered->contains('email', 'ccs.staff@cspc.edu.ph'), 'their own college staff should be offered');

        foreach (['chs.dean@cspc.edu.ph', 'cea.dean@cspc.edu.ph', 'chs.chair@cspc.edu.ph', 'chs.staff@cspc.edu.ph'] as $stranger) {
            $this->assertFalse(
                $offered->contains('email', $stranger),
                "{$stranger} belongs to another college and must not be offered"
            );
        }

        // Only one dean on the page at all, so there is nothing to mis-click.
        $deans = $offered->filter(fn (User $u) => optional($u->role)->name === 'Dean');
        $this->assertCount(1, $deans);

        fwrite(STDERR, "  [college] the picker shows one dean -- theirs -- and nobody from another college\n");
    }

    /**
     * A chair chairs one programme, so only that one is offered.
     *
     * Scoping to the college was not enough: Computer Studies has four chairs,
     * and a BS Information Systems case listed all four. Three of them chair a
     * programme the student is not enrolled in and hold no standing over the
     * people in it -- they are not alternatives, they are mistakes waiting to
     * be clicked.
     */
    public function test_only_the_chair_of_the_students_own_programme_is_offered(): void
    {
        $ccs = 'College of Computer Studies';

        // The other three programmes of the same college.
        foreach ([
            'BS Information Technology' => 'bsit.chair@cspc.edu.ph',
            'BS Computer Science' => 'bscs.chair@cspc.edu.ph',
            'Bachelor of Library and Information Science' => 'blis.chair@cspc.edu.ph',
        ] as $programme => $email) {
            $this->person($programme.' Chair', $email, 'Program Chair', $ccs, ['course' => $programme]);
        }

        [$concern, $chair] = $this->aComputerStudiesCase();

        // Referred on by somebody else, so the chair themselves is a candidate.
        $counsellor = User::where('email', 'counselor@cspc.edu.ph')->first()
            ?? $this->person('A Counsellor', 'counsellor@cspc.edu.ph', 'Guidance Counselor', 'Guidance Office');

        $concern->forceFill(['assigned_to' => $counsellor->id])->save();

        $page = $this->actingAs($counsellor)->get("/concerns/{$concern->id}")->assertOk();
        $offered = collect($page->viewData('referralCandidates'))->flatten();

        $chairs = $offered->filter(fn (User $u) => optional($u->role)->name === 'Program Chair');

        $this->assertCount(1, $chairs, 'only the chair of their own programme should be offered');
        $this->assertSame('BS Information Systems', $chairs->first()->course);

        foreach (['bsit.chair@cspc.edu.ph', 'bscs.chair@cspc.edu.ph', 'blis.chair@cspc.edu.ph'] as $other) {
            $this->assertFalse($offered->contains('email', $other), "{$other} chairs another programme");
        }

        fwrite(STDERR, "  [college] one chair is offered -- the one who chairs the student's programme\n");
    }

    /**
     * Every college, not just Computer Studies.
     *
     * The scoping reads the concern's own department and programme, so there
     * is nothing college-specific in it -- but "it should be generic" is a
     * claim about code, and this is the claim about behaviour. Each college
     * gets a dean and two chairs, and a student of the first programme must
     * see their own dean and their own chair, and nobody else's.
     *
     * @return array<string, array{0: string}>
     */
    public static function colleges(): array
    {
        $cases = [];

        foreach (array_keys(User::COURSES_BY_COLLEGE) as $college) {
            $cases[$college] = [$college];
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('colleges')]
    public function test_it_applies_to_every_college(string $college): void
    {
        $programmes = User::COURSES_BY_COLLEGE[$college];
        $theirs = $programmes[0];
        $other = $programmes[1] ?? null;

        $slug = \Illuminate\Support\Str::slug($college);

        // Their own college.
        $dean = $this->person('Dean of '.$college, $slug.'.dean@cspc.edu.ph', 'Dean', $college);
        $chair = $this->person('Chair of '.$theirs, $slug.'.chair@cspc.edu.ph', 'Program Chair', $college, [
            'course' => $theirs,
        ]);

        $otherChair = $other
            ? $this->person('Chair of '.$other, $slug.'.chair2@cspc.edu.ph', 'Program Chair', $college, ['course' => $other])
            : null;

        // A different college, fully staffed.
        $elsewhere = collect(array_keys(User::COURSES_BY_COLLEGE))->first(fn ($c) => $c !== $college);
        $strangerDean = $this->person('Dean of '.$elsewhere, 'stranger.dean@cspc.edu.ph', 'Dean', $elsewhere);

        $student = User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();
        $student->forceFill([
            'department' => $college,
            'course' => $theirs,
            'section' => '2A',
        ])->save();

        $concern = Concern::create([
            'user_id' => $student->id,
            'category' => 'Academic',
            'department' => $college,
            'course' => $theirs,
            'section' => '2A',
            'description' => 'A concern from a student of '.$theirs.'.',
            'status' => 'submitted',
            'is_anonymous' => false,
            'assigned_to' => $dean->id,
        ]);

        $offered = collect(
            $this->actingAs($dean)->get("/concerns/{$concern->id}")->assertOk()->viewData('referralCandidates')
        )->flatten();

        // Their own chair is there; the college's other chair is not.
        $this->assertTrue($offered->contains('id', $chair->id), "{$college}: their own chair should be offered");

        if ($otherChair) {
            $this->assertFalse(
                $offered->contains('id', $otherChair->id),
                "{$college}: a chair of another programme should not be offered"
            );
        }

        // And nobody from another college at all.
        $this->assertFalse(
            $offered->contains('id', $strangerDean->id),
            "{$college}: a dean of {$elsewhere} should not be offered"
        );

        fwrite(STDERR, '  [college] '.$college." — own chair only, no outsiders\n");
    }

    /**
     * "Refer to Faculty/Staff" means the student's own college's staff.
     *
     * It used to offer every office in the institution -- Human Rights
     * Education, Records, Health Services, the ICT Unit -- for a concern from
     * one college. Those people have no part in it, and handing them a case is
     * handing it to a stranger.
     */
    public function test_referring_to_staff_offers_only_the_students_own_colleges_staff(): void
    {
        [$concern, $chair] = $this->aComputerStudiesCase();

        $elsewhere = $this->person('A Records Officer', 'records.officer@cspc.edu.ph', 'Faculty/Staff', 'Student Registration and Records');
        $ict = $this->person('An ICT Officer', 'ict.officer@cspc.edu.ph', 'Faculty/Staff', 'Information and Communications Technology Unit');

        $page = $this->actingAs($chair)->get("/concerns/{$concern->id}")->assertOk();
        $offered = collect($page->viewData('referralCandidates'))->flatten();

        $this->assertTrue(
            $offered->contains('email', 'ccs.staff@cspc.edu.ph'),
            'their own college\'s staff should be offered'
        );

        foreach ([$elsewhere, $ict] as $stranger) {
            $this->assertFalse(
                $offered->contains('id', $stranger->id),
                $stranger->department.' has no part in a Computer Studies concern'
            );
        }

        fwrite(STDERR, "  [college] referring to staff offers the student's own college, not every office\n");
    }

    /**
     * Nobody is offered their own office, whatever that office is.
     *
     * Referring a case to the office already reading it is not a hand-off,
     * so the destination is left out for every role the viewer holds. The
     * rule is not written per role; these check it holds for each in turn
     * rather than only for the adviser it was first noticed on.
     *
     * @return array<string, array{0: string}>
     */
    public static function handlerRoles(): array
    {
        return [
            'an adviser' => ['Adviser'],
            'an instructor' => ['Instructor'],
            'a program chair' => ['Program Chair'],
            'a dean' => ['Dean'],
            'a guidance counsellor' => ['Guidance Counselor'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('handlerRoles')]
    public function test_a_handler_is_never_offered_their_own_role(string $role): void
    {
        [$concern] = $this->aComputerStudiesCase();

        $handler = $this->person(
            'The Only '.$role,
            'sole.'.\Illuminate\Support\Str::slug($role).'@cspc.edu.ph',
            $role,
            'College of Computer Studies',
            $role === 'Program Chair' ? ['course' => 'BS Information Systems'] : []
        );

        $concern->forceFill(['assigned_to' => $handler->id])->save();

        $offered = $this->actingAs($handler)->get("/concerns/{$concern->id}")->assertOk()
            ->viewData('referralDestinations');

        $this->assertArrayNotHasKey(
            $role,
            $offered->all(),
            "the only {$role} must not be offered {$role} as a destination"
        );

        fwrite(STDERR, "  [college] {$role}: not offered their own role back\n");
    }

    /**
     * A colleague in the same role does not bring the office back.
     *
     * This used to go the other way: a second dean made "Dean" a real
     * hand-off and the destination stayed. In practice it only ever surfaced
     * the wrong people. One college has one dean and one programme has one
     * chair, so a second holder is either a placeholder or a stale record,
     * and a chair of another programme has no standing over this student --
     * which is how the chairs of Computer Science, Information Technology
     * and Library Science came to be offered on a BS Information Systems
     * case whose own chair was the one referring.
     *
     * The office is now left out for whoever holds it, colleague or not.
     */
    public function test_a_colleague_in_the_same_role_does_not_bring_it_back(): void
    {
        [$concern] = $this->aComputerStudiesCase();

        $first = $this->person('First Dean', 'first.dean@cspc.edu.ph', 'Dean', 'College of Computer Studies');
        $second = $this->person('Second Dean', 'second.dean@cspc.edu.ph', 'Dean', 'College of Computer Studies');

        $concern->forceFill(['assigned_to' => $first->id])->save();

        $offered = $this->actingAs($first)->get("/concerns/{$concern->id}")->assertOk()
            ->viewData('referralDestinations');

        $this->assertArrayNotHasKey(
            'Dean',
            $offered->all(),
            'a dean is not offered Dean, even with a second dean in the college'
        );

        // The colleague is still eligible underneath: it is the office that
        // is not offered, not the person who is disqualified. Anyone whose
        // own office is not Dean still reaches them by name.
        $candidates = $this->actingAs($first)->get("/concerns/{$concern->id}")
            ->viewData('referralCandidates')->get('Dean');

        $this->assertTrue($candidates->contains('id', $second->id), 'the colleague is still eligible');
        $this->assertFalse($candidates->contains('id', $first->id), 'never themselves');

        fwrite(STDERR, "  [college] a colleague in the same role does not bring the office back\n");
    }

    /**
     * The chairs of other programmes do not stand in for an absent one.
     *
     * Narrowing to the student's programme found nobody, because the one
     * person who chairs it was the one referring. The fallback then handed
     * back the whole college -- three chairs with no standing over this
     * student. It is meant for a programme with no chair recorded at all,
     * not for one whose chair is simply not an option here.
     */
    public function test_other_programmes_chairs_do_not_stand_in_for_an_absent_one(): void
    {
        // The case already belongs to the chair of the student's programme.
        [$concern, $theirs] = $this->aComputerStudiesCase();

        // A second chair in the same college, over a programme this student
        // is not enrolled in. This is the one that used to be offered.
        $other = $this->person(
            'The CS Chair', 'cs.chair@cspc.edu.ph', 'Program Chair',
            'College of Computer Studies', ['course' => 'BS Computer Science']
        );

        $candidates = $this->actingAs($theirs)->get("/concerns/{$concern->id}")->assertOk()
            ->viewData('referralCandidates');

        $chairs = $candidates->get('Program Chair') ?? collect();

        $this->assertFalse(
            $chairs->contains('id', $other->id),
            'a chair of another programme must not stand in for the student\'s own'
        );
        $this->assertTrue(
            $chairs->isEmpty(),
            'no chair stands in when the programme\'s own chair is the one referring'
        );

        fwrite(STDERR, "  [college] other programmes chairs do not stand in\n");
    }

    /**
     * The offices that serve everybody are not scoped away. Guidance, General
     * Services and the VPAA have no college, and a case that belongs with them
     * must still be able to get there.
     */
    public function test_the_central_offices_are_still_offered(): void
    {
        [$concern, $chair] = $this->aComputerStudiesCase();

        $page = $this->actingAs($chair)->get("/concerns/{$concern->id}")->assertOk();
        $offered = collect($page->viewData('referralCandidates'))->flatten();

        $this->assertTrue(
            $offered->contains(fn (User $u) => optional($u->role)->name === 'Guidance Counselor'),
            'the Guidance Office serves every college and must stay offered'
        );

        fwrite(STDERR, "  [college] the central offices are still reachable from any college\n");
    }
}
