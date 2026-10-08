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
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('signedInPages')]
    public function test_a_signed_in_page_is_not_stored(string $path): void
    {
        $response = $this->actingAs($this->student())->get($path)->assertOk();

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

    /** The login page is not made uncacheable for no reason. */
    public function test_the_login_page_is_left_alone(): void
    {
        $response = $this->get('/login')->assertOk();

        $this->assertStringNotContainsString(
            'no-store',
            strtolower((string) $response->headers->get('Cache-Control')),
            'nothing on the login page is worth slowing every visit for'
        );

        fwrite(STDERR, "  [back] the login page is left cacheable\n");
    }
}
