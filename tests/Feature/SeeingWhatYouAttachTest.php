<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Concern;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A student can see what they are attaching, and what they attached.
 *
 * "Send documents" on a concern was the bare browser control: it said
 * "Screenshot (2).png" and nothing else, so a student could not tell which
 * screenshot they had picked, and could not take one back out without
 * starting over. It shares the filing form's picker now, with a preview of
 * every picture. And a picture already sent opens to VIEW instead of
 * downloading -- for JPEG and PNG only, because an SVG opened in the
 * browser is a document that can run script.
 */
class SeeingWhatYouAttachTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
        Storage::fake('local');
    }

    private function student(): User
    {
        return User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();
    }

    private function concern(): Concern
    {
        $this->actingAs($this->student())->post('/concerns', [
            'category' => 'Facilities',
            'subcategory' => 'Water',
            'description' => 'No water in the second floor restroom for three days now.',
            'is_anonymous' => 0,
        ]);

        return Concern::latest('id')->firstOrFail();
    }

    private function attach(Concern $concern, string $name, string $mime): Attachment
    {
        $path = 'attachments/'.uniqid().'-'.$name;
        Storage::disk('local')->put($path, 'not really a '.$mime);

        return Attachment::create([
            'concern_id' => $concern->id,
            'kind' => Attachment::KIND_FOLLOW_UP,
            'uploaded_by' => $this->student()->id,
            'original_name' => $name,
            'stored_path' => $path,
            'mime_type' => $mime,
            'size_bytes' => 20,
        ]);
    }

    /** "Send documents" has the picker: list, preview, and an × per file. */
    public function test_send_documents_has_the_picker_with_a_preview(): void
    {
        $concern = $this->concern();

        $html = $this->actingAs($this->student())->get('/concerns/'.$concern->id)->assertOk()->getContent();

        $this->assertStringContainsString('id="documents-dropzone"', $html);
        $this->assertStringContainsString('name="documents[]"', $html);
        $this->assertStringContainsString('id="documents-file-list"', $html);
        $this->assertStringContainsString('new DataTransfer()', $html);
        $this->assertStringContainsString("remove.className = 'file-remove'", $html);
        $this->assertStringContainsString('URL.createObjectURL(file)', $html);

        fwrite(STDERR, "  [attach] send documents shows what is chosen, with a preview and an ×\n");
    }

    /** A picture already sent opens to view, and is shown as itself in the list. */
    public function test_a_sent_picture_opens_to_view(): void
    {
        $concern = $this->concern();
        $picture = $this->attach($concern, 'receipt.png', 'image/png');

        $this->actingAs($this->student())->get('/concerns/'.$concern->id)->assertOk()
            ->assertSee('class="attachment-thumb"', false)
            ->assertSee('?inline=1', false);

        $response = $this->actingAs($this->student())
            ->get(route('concerns.attachment', [$concern, $picture]).'?inline=1')
            ->assertOk();

        $this->assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'));

        fwrite(STDERR, "  [attach] a sent picture opens to view\n");
    }

    /** Anything that is not JPEG or PNG still downloads, whatever the link asks. */
    public function test_anything_else_still_downloads(): void
    {
        $concern = $this->concern();
        $svg = $this->attach($concern, 'drawing.svg', 'image/svg+xml');

        $response = $this->actingAs($this->student())
            ->get(route('concerns.attachment', [$concern, $svg]).'?inline=1')
            ->assertOk();

        $this->assertStringStartsWith('attachment', (string) $response->headers->get('Content-Disposition'));

        fwrite(STDERR, "  [attach] an SVG still downloads, never opens in the page\n");
    }

    /** Viewing is not a way round who may see the concern. */
    public function test_viewing_needs_the_same_permission_as_downloading(): void
    {
        $concern = $this->concern();
        $picture = $this->attach($concern, 'receipt.png', 'image/png');

        $stranger = User::whereHas('role', fn ($q) => $q->where('name', 'Student'))
            ->where('id', '!=', $this->student()->id)
            ->firstOrFail();

        $this->actingAs($stranger)
            ->get(route('concerns.attachment', [$concern, $picture]).'?inline=1')
            ->assertForbidden();

        fwrite(STDERR, "  [attach] another student cannot view it\n");
    }
}
