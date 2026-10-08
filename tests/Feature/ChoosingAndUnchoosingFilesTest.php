<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Choosing files, and taking one back.
 *
 * The native control offers no way to remove a single file: a FileList cannot
 * be edited, so picking again replaces the whole set. A student who selected
 * nine by mistake could only start over, and the form showed "9 files" with
 * nothing to click.
 *
 * DataTransfer is the way back -- build one, add the files to keep, assign
 * its list to the input. The input is still what submits; everything on top
 * only decides what is in it.
 *
 * The behaviour runs in the browser. This pins what the page has to hand it.
 */
class ChoosingAndUnchoosingFilesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    private function form(): string
    {
        return $this->actingAs(User::where('email', 'student@my.cspc.edu.ph')->firstOrFail())
            ->get('/concerns/create')->assertOk()->getContent();
    }

    /** The drop zone, and the input it wraps. */
    public function test_the_drop_zone_is_rendered_around_the_real_input(): void
    {
        $html = $this->form();

        $this->assertStringContainsString('id="dropzone"', $html);
        $this->assertStringContainsString('Drag files here', $html);

        // Still the real input, still submitting under the same name.
        $this->assertStringContainsString('name="attachments[]"', $html);
        $this->assertStringContainsString('id="attachments"', $html);

        fwrite(STDERR, "  [files] the drop zone wraps the real input\n");
    }

    /**
     * The file dialog offers what the server accepts.
     *
     * It was left at ".jpg,.jpeg,.png,.pdf" after video was allowed, so a
     * student could not even see their clip in the picker.
     */
    public function test_the_picker_offers_every_accepted_kind(): void
    {
        $html = $this->form();

        foreach (['.mp4', '.mov', '.png', '.pdf', '.docx', '.zip', '.m4a'] as $extension) {
            $this->assertStringContainsString($extension, $html, $extension.' should be offered');
        }

        fwrite(STDERR, "  [files] the picker offers video, sound, documents and pictures\n");
    }

    /** The parts that let a file be taken back out. */
    public function test_the_page_can_remove_a_chosen_file(): void
    {
        $html = $this->form();

        // The list it renders into...
        $this->assertStringContainsString('id="file-list"', $html);

        // ...the button on each row...
        $this->assertStringContainsString("remove.className = 'file-remove'", $html);
        $this->assertStringContainsString('chosen.splice(index, 1)', $html);

        // ...and the only thing that can rewrite a FileList.
        $this->assertStringContainsString('new DataTransfer()', $html);
        $this->assertStringContainsString('input.files = transfer.files', $html);

        fwrite(STDERR, "  [files] a chosen file can be removed, and the input rewritten\n");
    }

    /** The limits are the server's, not numbers typed into the script. */
    public function test_the_script_is_driven_by_the_real_limits(): void
    {
        $html = $this->form();

        $this->assertStringContainsString(
            'var MAX_FILES = '.config('concerns.max_attachments').';',
            $html
        );
        $this->assertStringContainsString(
            'var MAX_BYTES = '.config('concerns.max_attachment_mb').' * 1024 * 1024;',
            $html
        );

        fwrite(STDERR, '  [files] the script uses '.config('concerns.max_attachments')
            .' files / '.config('concerns.max_attachment_mb')." MB\n");
    }
}
