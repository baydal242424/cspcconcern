<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Signed-in pages are not left in the browser's cache.
 *
 * Logging out ends the session, and the next REQUEST is refused -- but the
 * Back button does not make a request. The browser re-displays the copy it
 * already holds, so a student who logs out on a shared machine leaves their
 * concern list and their filing form one keypress away from whoever sits
 * down next.
 *
 * "no-store" is the header that counts. "no-cache" alone permits a stored
 * copy to be shown after revalidation, and with no network request there is
 * nothing to revalidate against.
 */
class BackButtonAfterLogoutTest extends TestCase
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

    /** @return array<string, array{0: string}> */
    public static function signedInPages(): array
    {
        return [
            'the concern list' => ['/concerns'],
            'the filing form' => ['/concerns/create'],
            // Outside the auth groups, and still renders the navbar: a
            // cached copy of this showed the signed-in bar after logout,
            // which is the page the problem was reported on.
            'the policy page' => ['/policy'],
            'the landing page' => ['/'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('signedInPages')]
    public function test_a_signed_in_page_is_not_stored(string $path): void
    {
        // Not assertOk: the landing page redirects a signed-in student to
        // their list, and a redirect is cached like anything else.
        $response = $this->actingAs($this->student())->get($path);

        $cacheControl = strtolower((string) $response->headers->get('Cache-Control'));

        $this->assertStringContainsString('no-store', $cacheControl, $path);
        $this->assertSame('no-cache', $response->headers->get('Pragma'));

        fwrite(STDERR, "  [back] {$path}: no-store\n");
    }

    /** After logging out, the same URL asks them to sign in. */
    public function test_the_page_is_gone_once_they_log_out(): void
    {
        $this->actingAs($this->student())->get('/concerns')->assertOk();

        $this->post('/logout')->assertRedirect();

        $this->get('/concerns')->assertRedirect(route('login'));
        $this->assertGuest();

        fwrite(STDERR, "  [back] after logout the URL sends them to sign in\n");
    }

    /**
     * Every page, including the login page.
     *
     * Sparing it was the first attempt, on the reasoning that nothing there
     * is private. The rule has to be whole to be worth anything: the policy
     * page and the landing page are not private either, and both render the
     * navbar, so a cached copy of either came back wearing the signed-in bar.
     * One uncached page is one page the Back button can still return to.
     */
    public function test_even_the_login_page_is_not_stored(): void
    {
        $response = $this->get('/login')->assertOk();

        $this->assertStringContainsString(
            'no-store',
            strtolower((string) $response->headers->get('Cache-Control'))
        );

        fwrite(STDERR, "  [back] the login page is not stored either\n");
    }
}
