<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\ConcernCategory;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two offices that existed as departments, now receiving work.
 *
 * A physical injury went to the Guidance Counselor, because every category
 * that was not obviously somebody else's went there. A student who has been
 * hurt needs a nurse first; counselling is what comes after, if at all. The
 * clinic was in the system as a DEPARTMENT on a Faculty/Staff account --
 * which routing cannot see -- so it could be named in a concern and never
 * handed one.
 *
 * The library had no category at all, so a complaint about a fine or a
 * missing book was filed under "Others" and routed to a class adviser.
 */
class TheClinicAndTheLibraryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
        ConcernCategory::forget();
    }

    private function student(): User
    {
        return User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();
    }

    /** Both offices exist as roles, not merely as departments. */
    public function test_the_clinic_and_the_library_are_roles(): void
    {
        foreach (['Clinic', 'Library'] as $office) {
            $this->assertNotNull(Role::where('name', $office)->first(), "{$office} is a role");

            // Listed as an employee role, or an employee-wide operation
            // would skip them and they would survive it silently.
            $this->assertContains($office, User::EMPLOYEE_ROLES);
        }

        fwrite(STDERR, "  [offices] the clinic and the library are roles\n");
    }

    /** A physical injury reaches the clinic, not the counsellor. */
    public function test_a_physical_injury_reaches_the_clinic(): void
    {
        // The seeder staffs the clinic -- Mr. Manzano, who was already in
        // the system as Faculty/Staff in the Health Services Unit. The
        // assertion is on the ROLE rather than on him: which person holds
        // the desk is the seeder's business, and naming him here would
        // break this test the day somebody else takes it over.
        $this->assertSame('Clinic', ConcernCategory::routingFor('Physical'));

        $this->actingAs($this->student())->post('/concerns', [
            'category' => 'Physical',
            'subcategory' => ConcernCategory::subcategoriesFor('Physical')[0] ?? null,
            'description' => 'I slipped on the stairs and hurt my ankle badly.',
        ])->assertRedirect();

        $concern = Concern::latest('id')->firstOrFail();

        $this->assertSame('Clinic', optional(optional($concern->assignedUser)->role)->name);
        $this->assertNotSame('Guidance Counselor', optional(optional($concern->assignedUser)->role)->name);

        fwrite(STDERR, "  [offices] a physical injury reaches the clinic\n");
    }

    /** The library is on the filing form, with somewhere to send it. */
    public function test_a_library_concern_can_be_filed_and_routed(): void
    {
        $this->assertContains('Library', ConcernCategory::offered());
        $this->assertContains('Fines and penalties', ConcernCategory::subcategoriesFor('Library'));

        $this->actingAs($this->student())->get('/concerns/create')->assertOk()
            ->assertSee('value="Library"', false);

        $this->flushSession();
        $this->actingAs($this->student())->post('/concerns', [
            'category' => 'Library',
            'subcategory' => 'Fines and penalties',
            'description' => 'I was charged a fine for a book I returned on time.',
        ])->assertRedirect();

        $concern = Concern::latest('id')->firstOrFail();

        $this->assertSame('Library', $concern->category);
        $this->assertSame('Library', optional(optional($concern->assignedUser)->role)->name);

        fwrite(STDERR, "  [offices] a library concern is filed and routed\n");
    }

    /**
     * Nobody in the office is not nobody at all.
     *
     * A role with no people in it must not swallow a concern. routeConcern()
     * climbs its escalation chain instead, so the case lands on somebody who
     * can see it is in the wrong place -- rather than sitting unassigned and
     * readable by no one but the student who filed it.
     */
    public function test_an_empty_office_still_lands_the_concern_somewhere(): void
    {
        // Empty the desk first. The seeder staffs it, because a library
        // category routed at nobody hands library matters to whoever the
        // escalation chain reaches -- but the chain still has to hold when
        // an office is vacant, which is what this is about.
        User::whereHas('role', fn ($q) => $q->where('name', 'Library'))->forceDelete();

        $this->assertSame(0, User::whereHas('role', fn ($q) => $q->where('name', 'Library'))->count(),
            'this test needs the library empty');

        $this->actingAs($this->student())->post('/concerns', [
            'category' => 'Library',
            'subcategory' => 'Clearance',
            'description' => 'My library clearance has not been signed for two weeks.',
        ])->assertRedirect();

        $concern = Concern::latest('id')->firstOrFail();

        $this->assertNotNull($concern->assigned_to, 'an empty office must not swallow a concern');

        fwrite(STDERR, "  [offices] an empty office still lands the concern somewhere\n");
    }

    /**
     * The records office has a category, and it reaches Dr. Malaya's desk.
     *
     * The Registrar became a role and had nothing routed to it: nameable,
     * referrable, never the first desk a concern reached. A student whose
     * transcript had not been released filed under "Others" and it went to
     * their class adviser.
     */
    public function test_a_registrar_concern_reaches_the_records_office(): void
    {
        $this->assertContains('Registrar', ConcernCategory::offered());
        $this->assertSame('Registrar', ConcernCategory::routingFor('Registrar'));

        $subs = ConcernCategory::subcategoriesFor('Registrar');

        $this->assertGreaterThanOrEqual(5, count($subs), 'at least five, as asked');
        $this->assertContains('Transcript of Records', $subs);

        $this->actingAs($this->student())->post('/concerns', [
            'category' => 'Registrar',
            'subcategory' => 'Transcript of Records',
            'description' => 'I requested my transcript three weeks ago and have heard nothing since.',
        ])->assertRedirect();

        $concern = Concern::latest('id')->firstOrFail();

        $this->assertSame('Registrar', optional(optional($concern->assignedUser)->role)->name);

        fwrite(STDERR, "  [offices] a registrar concern reaches the records office\n");
    }

    /**
     * It does not repeat what Academic already asks.
     *
     * Academic carries Enrollment, Requirements and Deadlines, and those
     * are arguably this office's work too -- but students have been filing
     * under them, so moving them would recategorise real concerns and
     * leave two forms asking one question. The line drawn instead:
     * Academic is about STUDYING, the Registrar about the RECORD of it.
     */
    public function test_the_registrar_does_not_duplicate_academic(): void
    {
        $registrar = ConcernCategory::subcategoriesFor('Registrar');
        $academic = ConcernCategory::subcategoriesFor('Academic');

        $this->assertSame([], array_intersect($registrar, $academic),
            'no sub-category appears under both');

        // A wrong grade is the instructor's. A grade that is right but
        // missing from the record is this office's. Both exist, and they
        // are not the same words.
        $this->assertContains('Grades', $academic);
        $this->assertContains('Grade missing from my record', $registrar);

        fwrite(STDERR, "  [offices] the registrar does not repeat Academic\n");
    }

    /**
     * "Others" stays at the bottom, whatever is added above it.
     *
     * It was simply the last one seeded, so the Library landed under it --
     * and a named category sitting below the bucket for everything that
     * has no name reads as an afterthought to it.
     */
    public function test_others_is_last_on_the_filing_form(): void
    {
        $offered = ConcernCategory::offered();

        $this->assertSame('Others', end($offered), 'the catch-all comes last');
        $this->assertContains('Library', $offered);

        // And it stays last when the office adds another.
        $admin = User::whereHas('role', fn ($q) => $q->where('name', 'System Admin'))->firstOrFail();

        $this->actingAs($admin)->post('/admin/categories', [
            'name' => 'Canteen',
            'label' => 'Canteen & Food Services',
            'routing_role' => 'General Services',
        ])->assertRedirect();

        ConcernCategory::forget();

        $after = ConcernCategory::offered();

        $this->assertSame('Others', end($after), 'still last after a category is added');
        $this->assertContains('Canteen', $after);

        fwrite(STDERR, "  [offices] Others stays at the bottom of the list\n");
    }

    /** The library asks only what the library can answer for. */
    public function test_the_library_sub_categories_are_the_ones_it_owns(): void
    {
        $subs = ConcernCategory::subcategoriesFor('Library');

        // A disputed charge, an unsigned clearance, and somebody at the
        // desk: the three where the library is both the subject and the
        // only office that can settle it.
        $this->assertContains('Fines and penalties', $subs);
        $this->assertContains('Clearance', $subs);
        $this->assertContains('Library staff conduct', $subs);

        // The reading room itself belongs to Facilities, which already
        // has Rooms, Aircon, Lighting and Furniture. Listing it here
        // would send a broken chair to a librarian.
        $this->assertNotContains('Noise or behaviour', $subs);
        $this->assertNotContains('Opening hours', $subs);

        // And this one pointed the wrong way: a missing or damaged book
        // is the library's complaint about the student.
        $this->assertNotContains('Missing or damaged book', $subs);

        fwrite(STDERR, "  [offices] the library asks only what it can answer for\n");
    }

    /**
     * Both can be referred to.
     *
     * The ICT Unit was added as a destination and never given a line in
     * REFERRAL_ROLE_LABELS, which the view iterates -- so for weeks it was
     * a destination nobody could pick. These two are not repeating that.
     */
    public function test_both_offices_can_be_named_as_a_destination(): void
    {
        $labels = \App\Http\Controllers\ConcernController::REFERRAL_ROLE_LABELS;

        foreach (['Clinic', 'Library', 'ICT Unit'] as $office) {
            $this->assertArrayHasKey($office, $labels, "{$office} needs a label to be offered");
            $this->assertNotSame('', trim($labels[$office]));
        }

        fwrite(STDERR, "  [offices] both can be named as a referral destination\n");
    }
}
