<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A fault in the website is reported from the footer, not from the form.
 *
 * "System Problem" was the sixth of eleven categories on the filing form,
 * between Harassment and Building & Facilities. A student opening that form
 * has something to report about the college -- a grade, a person, a broken
 * toilet -- and a page that will not load was sitting in the same list,
 * which both lengthened the list everyone reads and put a software bug on
 * the same footing as being hurt.
 *
 * So it comes off the list and goes into the footer as a small bug icon:
 * reachable from whatever page has just misbehaved, which is where somebody
 * notices a fault in the first place.
 *
 * The stored value does not change. 'Administrative' is matched by name in
 * routing and in the staff filter, and 48 concerns already carry it, so this
 * is a change to what the form OFFERS and not to what the server accepts.
 */
class TheBugLinkFilesASiteProblemTest extends TestCase
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

    /** The category list a student scrolls is one shorter. */
    public function test_the_filing_form_does_not_offer_a_website_fault(): void
    {
        $page = $this->actingAs($this->student())->get('/concerns/create')->assertOk();

        $page->assertDontSee('value="Administrative"', false);
        $page->assertDontSee('System Problem');

        // Everything else is still there: this removes one option, not a
        // column of them.
        foreach (Concern::pickerCategories() as $category) {
            $page->assertSee('value="'.$category.'"', false);
        }

        $this->assertCount(count(Concern::CATEGORIES) - 1, Concern::pickerCategories());
        $this->assertNotContains(Concern::WEBSITE_FAULT, Concern::pickerCategories());

        fwrite(STDERR, "  [bug] the filing form no longer offers a website fault\n");
    }

    /** The footer carries the way in, as a bug icon with a label. */
    public function test_the_footer_carries_a_bug_link_beside_the_privacy_policy(): void
    {
        // Any page on the shared layout will do -- the footer is the layout's,
        // not one page's, which is the point of putting the link there.
        $page = $this->actingAs($this->student())->get('/concerns')->assertOk();

        $html = $page->getContent();

        // The anchor's class attribute, not the bare word: 'footer-bug' also
        // names the CSS rule in the layout's <style> block, which every page
        // carries whether the link is drawn or not. A test matching that
        // would pass with no link on the page at all -- and did, the first
        // time it ran.
        $page->assertSee('class="footer-link footer-bug"', false);
        $page->assertSee('Report a site problem');
        $page->assertSee('Report a problem with this website', false);

        // Beside the policy link, which is what was asked for -- and after
        // it, so the legal link keeps its place.
        $policyAt = strpos($html, 'Data Privacy &amp; Confidentiality Policy');
        $bugAt = strpos($html, 'class="footer-link footer-bug"');

        $this->assertNotFalse($policyAt);
        $this->assertNotFalse($bugAt);
        $this->assertGreaterThan($policyAt, $bugAt, 'the bug link sits beside the privacy policy');

        // And it points at the filing form carrying the category, since the
        // form itself no longer offers it.
        $this->assertStringContainsString(
            'category='.Concern::WEBSITE_FAULT,
            $html
        );

        fwrite(STDERR, "  [bug] the footer carries the bug link beside the policy\n");
    }

    /**
     * Following it opens the form with the category already chosen.
     *
     * This is the half that makes the removal safe: take the option off the
     * list and leave the link pointing at a form that cannot show it, and
     * the student lands on "-- Select a category --" with no way to say what
     * they came to say.
     */
    public function test_following_the_link_opens_the_form_on_that_category(): void
    {
        $page = $this->actingAs($this->student())
            ->get('/concerns/create?category='.Concern::WEBSITE_FAULT)
            ->assertOk();

        // The option exists on this page, and it is the selected one.
        $page->assertSee('value="Administrative" selected', false);
        $page->assertSee('System Problem');

        fwrite(STDERR, "  [bug] the link opens the form on System Problem\n");
    }

    /**
     * The query string is not a back door.
     *
     * ?category= is a public parameter. Were it trusted as given, it would
     * become a way to open the form on any category the picker withholds --
     * there is one today and there may be more.
     */
    public function test_another_category_in_the_query_string_is_ignored(): void
    {
        $page = $this->actingAs($this->student())
            ->get('/concerns/create?category=Harassment')
            ->assertOk();

        // Harassment is on the list anyway, so it is offered -- but NOT
        // pre-selected, because nothing pre-selects from the query string
        // except the one whitelisted value.
        $page->assertSee('value="Harassment"', false);
        $page->assertDontSee('value="Harassment" selected', false);

        fwrite(STDERR, "  [bug] only the one whitelisted category pre-selects\n");
    }

    /** Filed through the link, it reaches the System Admin as before. */
    public function test_a_site_problem_still_routes_to_the_system_admin(): void
    {
        $this->actingAs($this->student())->post('/concerns', [
            'category' => Concern::WEBSITE_FAULT,
            'subcategory' => 'Cannot sign in',
            'description' => 'The sign-in page returns an error after I choose my CSPC account.',
        ])->assertRedirect();

        $concern = Concern::latest('id')->firstOrFail();

        $this->assertSame('Administrative', $concern->category);
        $this->assertSame('System Problem', $concern->category_label);
        $this->assertNotNull($concern->assigned_to, 'a site problem still lands on a desk');
        $this->assertSame(
            'System Admin',
            optional(optional($concern->assignedUser)->role)->name
        );

        fwrite(STDERR, "  [bug] a filed site problem still reaches the System Admin\n");
    }

    /**
     * A guest does not get the link.
     *
     * The form behind it is behind auth, so a guest would be bounced to the
     * sign-in page -- which is no help at all to the person whose problem IS
     * the sign-in page, and they have no account to file from either.
     */
    public function test_a_signed_out_visitor_is_not_offered_it(): void
    {
        // The policy page, not the sign-in page: sign-in is drawn on its own
        // standalone layout and has no footer to leave the link out of.
        $this->get('/policy')->assertOk()
            ->assertSee('Data Privacy')
            ->assertDontSee('class="footer-link footer-bug"', false);

        fwrite(STDERR, "  [bug] a guest is not offered the bug link\n");
    }

    /**
     * The 48 concerns already on file are untouched, and staff can still
     * find them. The category comes off the FILING form; the staff filter
     * reads the full list, or a whole category of past cases becomes
     * unsearchable.
     */
    public function test_staff_can_still_filter_the_category(): void
    {
        $admin = User::whereHas('role', fn ($q) => $q->where('name', 'System Admin'))
            ->firstOrFail();

        $this->actingAs($admin)->get('/concerns')->assertOk()
            ->assertSee('value="Administrative"', false);

        $this->assertContains(Concern::WEBSITE_FAULT, Concern::CATEGORIES);

        fwrite(STDERR, "  [bug] staff can still filter past site problems\n");
    }
}
