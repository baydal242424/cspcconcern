<?php

namespace Tests\Feature;

use App\Http\Controllers\ConcernController;
use App\Models\Concern;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The Legal Affairs Office has a door of its own.
 *
 * It used to sit inside the Gender and Development role, because referred_to
 * stores a ROLE NAME and there was none to store for legal counsel. The
 * reasoning was sound -- the handbook needs the Disciplinary Board chaired by
 * a member of the Bar, and CMO No. 3 s. 2022 cases are legal matters -- but
 * the effect was two unrelated offices behind one door: choosing GAD offered
 * the lawyer, and choosing the lawyer meant choosing GAD.
 */
class LegalAffairsIsItsOwnDoorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    private function lawyer(): User
    {
        return User::where('email', 'lao@cspc.edu.ph')->firstOrFail();
    }

    private function gadOfficer(): User
    {
        return User::where('email', 'gad@cspc.edu.ph')->firstOrFail();
    }

    public function test_the_two_offices_hold_different_roles(): void
    {
        $this->assertSame('Legal Affairs', $this->lawyer()->role->name);
        $this->assertSame('Gender and Development', $this->gadOfficer()->role->name);

        // And she holds ONE account. A second row at Human Rights Education
        // used to be seeded for her other directory address; it put the same
        // name twice into every staff picker, with only a role to tell the
        // two apart.
        $this->assertSame(
            1,
            User::where('name', 'Atty. Maria Francia S. Abaca')->count(),
            'one person, one account'
        );

        fwrite(STDERR, "  [legal] the lawyer and the GAD officer hold different roles\n");
    }

    public function test_it_is_offered_as_a_destination_of_its_own(): void
    {
        $this->assertContains('Legal Affairs', ConcernController::REFERRAL_ROLES);

        $this->assertSame(
            'Legal Affairs Office (legal counsel)',
            ConcernController::REFERRAL_ROLE_LABELS['Legal Affairs']
        );

        fwrite(STDERR, "  [legal] it is its own line in the Refer to list\n");
    }

    /** Choosing GAD offers the GAD officer, and nobody else. */
    public function test_choosing_gad_no_longer_offers_the_lawyer(): void
    {
        $counsellor = User::where('email', 'counselor@cspc.edu.ph')->firstOrFail();

        $concern = Concern::create([
            'user_id' => User::where('email', 'student@my.cspc.edu.ph')->firstOrFail()->id,
            'category' => 'Harassment',
            'department' => 'College of Computer Studies',
            'description' => 'A case the counsellor may need to refer onward.',
            'status' => 'submitted',
            'assigned_to' => $counsellor->id,
            'is_anonymous' => false,
        ]);

        $offered = collect(
            $this->actingAs($counsellor)->get("/concerns/{$concern->id}")->assertOk()
                ->viewData('referralCandidates')
        );

        $gad = collect($offered->get('Gender and Development') ?? []);
        $legal = collect($offered->get('Legal Affairs') ?? []);

        $this->assertTrue($gad->contains('id', $this->gadOfficer()->id));
        $this->assertFalse($gad->contains('id', $this->lawyer()->id), 'the lawyer is not GAD');

        $this->assertTrue($legal->contains('id', $this->lawyer()->id), 'and has a group of her own');

        fwrite(STDERR, "  [legal] choosing GAD offers the GAD officer; the lawyer has her own group\n");
    }

    /** A case referred to legal counsel reaches her, and she can read it. */
    public function test_a_case_can_still_be_referred_to_legal_counsel(): void
    {
        $counsellor = User::where('email', 'counselor@cspc.edu.ph')->firstOrFail();

        $concern = Concern::create([
            'user_id' => User::where('email', 'student@my.cspc.edu.ph')->firstOrFail()->id,
            'category' => 'Harassment',
            'department' => 'College of Computer Studies',
            'description' => 'A CMO No. 3 matter that needs legal counsel.',
            'status' => 'submitted',
            'assigned_to' => $counsellor->id,
            'is_anonymous' => false,
        ]);

        // Before the referral she has no standing view of it.
        $this->assertFalse(Concern::whereKey($concern->id)->visibleTo($this->lawyer())->exists());

        $this->actingAs($counsellor)->put("/concerns/{$concern->id}", [
            'status' => 'referred',
            'referred_to' => 'Legal Affairs',
            'investigation_notes' => 'Assessed, and it falls under CMO No. 3.',
            'resolution_notes' => 'Passing it to legal counsel.',
        ])->assertSessionHasNoErrors();

        $concern->refresh();

        $this->assertSame('Legal Affairs', $concern->referred_to);
        $this->assertSame($this->lawyer()->id, $concern->assigned_to);
        $this->assertTrue(Concern::whereKey($concern->id)->visibleTo($this->lawyer())->exists());

        fwrite(STDERR, "  [legal] a case referred to legal counsel reaches her, and she can read it\n");
    }

    /** Her door opens nothing else: no standing view of any category. */
    public function test_she_reads_only_what_is_sent_to_her(): void
    {
        $student = User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();

        foreach (Concern::CATEGORIES as $category) {
            $concern = Concern::create([
                'user_id' => $student->id,
                'category' => $category,
                'department' => 'College of Computer Studies',
                'description' => 'A concern filed to check what legal counsel can read.',
                'status' => 'submitted',
                'is_anonymous' => false,
            ]);

            $this->assertFalse(
                Concern::whereKey($concern->id)->visibleTo($this->lawyer())->exists(),
                "Legal Affairs must not have a standing view of {$category}"
            );
        }

        fwrite(STDERR, "  [legal] she reads what is sent to her and nothing else\n");
    }
}
