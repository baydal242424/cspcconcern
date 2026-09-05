<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every list that hides rows with `element.hidden = true` must also carry an
 * author rule making [hidden] stick.
 *
 * `[hidden] { display: none }` comes from the browser's own stylesheet, and any
 * author rule beats it -- so an element styled `display: flex` stays on screen
 * with `hidden` set, and nothing anywhere reports a problem. The JavaScript
 * runs, the counter updates, and the rows sit there.
 *
 * This has now happened three times in this project:
 *
 *   .field           the programme picker on the admin form ignored @unless
 *   .user-card       Manage Users counted "1 account" with every card visible
 *   .person          the student's instructor search narrowed nothing, on the
 *                    one list long enough to need it -- 368 names
 *
 * The bug is invisible to every other kind of test: the markup is correct, the
 * attribute is set, and only the cascade disagrees. So this asserts the rule
 * exists, which is the thing that keeps getting left out.
 */
class HiddenRowsActuallyHideTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    /** Manage Users: the account cards the search box filters. */
    public function test_admin_account_cards_can_be_hidden(): void
    {
        $page = $this->actingAs(User::where('email', 'admin@cspc.edu.ph')->firstOrFail())
            ->get(route('admin.users'));

        $page->assertOk();

        $this->assertMatchesRegularExpression(
            '/\.user-card\[hidden\]\s*\{[^}]*display\s*:\s*none/',
            $page->getContent(),
            'Searching hides cards with card.hidden = true, but .user-card is display:flex — '
            .'without .user-card[hidden]{display:none} the filter hides nothing.'
        );

        fwrite(STDERR, "  [cascade] admin account cards really hide: YES\n");
    }

    /** The filing form: the instructor and staff pickers. */
    public function test_people_picker_rows_can_be_hidden(): void
    {
        $page = $this->actingAs(User::where('email', 'student@my.cspc.edu.ph')->firstOrFail())
            ->get('/concerns/create');

        $page->assertOk();

        $this->assertMatchesRegularExpression(
            '/\.person\[hidden\]\s*\{[^}]*display\s*:\s*none/',
            $page->getContent(),
            'The name filter and the other-colleges fold both set row.hidden, but .person is '
            .'display:flex — without .person[hidden]{display:none} neither one hides anything.'
        );

        fwrite(STDERR, "  [cascade] people-picker rows really hide: YES\n");
    }
}
