<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The size limit on the form is the one the server keeps.
 *
 * "5 MB each" was printed under the file picker for months while PHP was set
 * to upload_max_filesize=2M. A 3 MB file was refused before Laravel was
 * reached, so the student got a blank page instead of the message explaining
 * why -- the app never saw the request and had nothing to say about it.
 *
 * The number is read back from PHP now, so the form, the validation rule and
 * the error text cannot drift from what the machine will actually take.
 */
class TheAttachmentLimitTellsTheTruthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    /** Whatever PHP allows, the app claims no more. */
    public function test_the_limit_never_exceeds_what_php_accepts(): void
    {
        $toBytes = static function (string $size): int {
            $size = trim($size);

            if ($size === '' || $size === '-1') {
                return PHP_INT_MAX;
            }

            return match (strtolower(substr($size, -1))) {
                'g' => (int) $size * 1024 * 1024 * 1024,
                'm' => (int) $size * 1024 * 1024,
                'k' => (int) $size * 1024,
                default => (int) $size,
            };
        };

        $ceilingMb = (int) floor(min(
            $toBytes((string) ini_get('upload_max_filesize')),
            $toBytes((string) ini_get('post_max_size'))
        ) / 1048576);

        $this->assertLessThanOrEqual(
            max(1, $ceilingMb),
            config('concerns.max_attachment_mb'),
            'the form must not promise more than PHP will take'
        );

        fwrite(STDERR, '  [limit] PHP allows '.$ceilingMb.' MB; the app claims '
            .config('concerns.max_attachment_mb')." MB\n");
    }

    /**
     * If the form names a size at all, it names the real one.
     *
     * It used to print "up to 5 files, 2 MB each" under the drop zone, and
     * this asserted that line verbatim. The line is gone -- the form had
     * grown a sentence of guidance under every field -- so what is left to
     * guard is the thing that line was for: never telling a student a
     * figure the server will not honour. A form that promises nothing
     * cannot promise wrongly, and a file that is too big is still refused
     * with the real number (see the test below).
     */
    public function test_the_form_never_names_a_size_it_will_not_honour(): void
    {
        $student = User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();

        $html = $this->actingAs($student)->get('/concerns/create')->assertOk()->getContent();

        $real = (int) config('concerns.max_attachment_mb');

        // Any "<number> MB" on the page has to be the real limit.
        preg_match_all('~(\d+)(?:&nbsp;|\s)*MB~i', $html, $found);

        foreach ($found[1] as $printed) {
            $this->assertSame(
                $real,
                (int) $printed,
                "The form shows {$printed} MB; the server enforces {$real} MB."
            );
        }

        fwrite(STDERR, '  [limit] the form names no size other than the real one ('
            .count($found[1])." shown)\n");
    }

    /** A file over it is refused with a message that names the real figure. */
    public function test_the_error_names_the_real_figure(): void
    {
        $student = User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();
        $overBy1KB = (config('concerns.max_attachment_mb') * 1024) + 1;

        $this->actingAs($student)->from('/concerns/create')->post('/concerns', [
            'category' => 'Academic',
            'subcategory' => 'Grades',
            'department' => 'College of Computer Studies',
            'description' => 'a description long enough to pass the rule',
            'attachments' => [\Illuminate\Http\UploadedFile::fake()->create('big.pdf', $overBy1KB, 'application/pdf')],
        ])->assertSessionHasErrors('attachments.0');

        $message = implode(' ', session('errors')->get('attachments.0'));

        $this->assertStringContainsString(
            (string) config('concerns.max_attachment_mb').' MB',
            $message
        );

        fwrite(STDERR, "  [limit] the error names the real figure\n");
    }
}
