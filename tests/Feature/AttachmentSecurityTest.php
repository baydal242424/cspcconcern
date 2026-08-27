<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User; use App\Models\Concern; use App\Models\Attachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Database\Seeders\RoleSeeder; use Database\Seeders\UserSeeder;

class AttachmentSecurityTest extends TestCase {
    use RefreshDatabase;
    protected function setUp():void{
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
        Storage::fake('local');
    }
    private function u($e){return User::where('email',$e)->firstOrFail();}
    private function line($t){fwrite(STDERR,"  $t\n");}

    /** Guardrail 1+2+4: a valid image uploads, stored on private disk with a randomized name */
    public function test_valid_image_uploads_and_is_stored_privately(): void {
        $this->actingAs($this->u('student@my.cspc.edu.ph'))->post('/concerns',[
            'category'=>'Academic','department'=>'College of Computer Studies',
            'description'=>'a concern submitted with photo evidence attached',
            'attachments'=>[UploadedFile::fake()->create('proof.jpg', 100, 'image/jpeg')],
        ]);
        $c=Concern::where('description','a concern submitted with photo evidence attached')->firstOrFail();
        $a=$c->attachments()->first();
        $this->line("[upload] attachment saved: ".($a?'yes':'no').", stored_path=".($a?$a->stored_path:'-'));
        $this->assertNotNull($a);
        $this->assertStringNotContainsString('proof.jpg',$a->stored_path,'Stored name must be randomized, not original');
        $this->assertEquals('proof.jpg',$a->original_name,'Original name kept for display');
        Storage::disk('local')->assertExists($a->stored_path);
    }

    /** Guardrail 1: an executable/php file is rejected */
    public function test_php_file_is_rejected(): void {
        $resp=$this->actingAs($this->u('student@my.cspc.edu.ph'))->post('/concerns',[
            'category'=>'Academic','department'=>'College of Computer Studies','description'=>'evil',
            'attachments'=>[UploadedFile::fake()->create('shell.php',10,'application/x-php')],
        ]);
        $resp->assertSessionHasErrors('attachments.0');
        $this->line("[reject] php upload rejected: yes; concern created=".(Concern::where('description','evil')->exists()?'yes':'no'));
        $this->assertFalse(Concern::where('description','evil')->exists(),'Concern with bad file should not be created');
    }

    /** Guardrail 5: oversized file (>5MB) is rejected */
    public function test_oversized_file_rejected(): void {
        $big=UploadedFile::fake()->create('big.pdf',6000,'application/pdf'); // 6 MB
        $resp=$this->actingAs($this->u('student@my.cspc.edu.ph'))->post('/concerns',[
            'category'=>'Academic','department'=>'College of Computer Studies','description'=>'toobig',
            'attachments'=>[$big],
        ]);
        $resp->assertSessionHasErrors('attachments.0');
        $this->line("[size] 6MB file rejected: yes");
    }

    /** Guardrail 6: more than 5 files rejected */
    public function test_too_many_files_rejected(): void {
        $files=[]; for($i=0;$i<6;$i++){$files[]=UploadedFile::fake()->create("f$i.png", 100, 'image/png');}
        $resp=$this->actingAs($this->u('student@my.cspc.edu.ph'))->post('/concerns',[
            'category'=>'Academic','department'=>'College of Computer Studies','description'=>'toomany',
            'attachments'=>$files,
        ]);
        $resp->assertSessionHasErrors('attachments');
        $this->line("[count] 6 files rejected: yes");
    }

    /** Guardrail 3 (THE BIG ONE): download enforces canViewConcern */
    public function test_download_requires_authorization(): void {
        // student submits a Mental Health concern with evidence, assigned to counselor
        $this->actingAs($this->u('student@my.cspc.edu.ph'))->post('/concerns',[
            'category'=>'Mental Health / Personal','department'=>'Guidance Office',
            'description'=>'sensitive evidence only the counselor may see',
            'attachments'=>[UploadedFile::fake()->create('private.jpg', 100, 'image/jpeg')],
        ]);
        $c=Concern::where('description','sensitive evidence only the counselor may see')->firstOrFail();
        $a=$c->attachments()->first();
        $url="/concerns/{$c->id}/attachments/{$a->id}";

        // owner can download
        $r1=$this->actingAs($this->u('student@my.cspc.edu.ph'))->get($url);
        $this->line("[authz] owner download -> ".$r1->getStatusCode()." (want 200)");
        $this->assertEquals(200,$r1->getStatusCode());

        // counselor (handles MH) can download
        $r2=$this->actingAs($this->u('counselor@cspc.edu.ph'))->get($url);
        $this->line("[authz] counselor download -> ".$r2->getStatusCode()." (want 200)");
        $this->assertEquals(200,$r2->getStatusCode());

        // a DIFFERENT student cannot
        $r3=$this->actingAs($this->u('student2@my.cspc.edu.ph'))->get($url);
        $this->line("[authz] other student download -> ".$r3->getStatusCode()." (want 403)");
        $r3->assertForbidden();

        // admin (not MH domain, uninvolved) cannot
        $r4=$this->actingAs($this->u('admin@cspc.edu.ph'))->get($url);
        $this->line("[authz] uninvolved admin download -> ".$r4->getStatusCode()." (want 403)");
        $r4->assertForbidden();

        // guest cannot
        auth()->logout();
        $r5=$this->get($url);
        $this->line("[authz] guest download -> ".$r5->getStatusCode()." (want redirect)");
        $r5->assertRedirect();
    }

    /** Guardrail 3b: reported staff cannot grab evidence of a concern about them */
    public function test_reported_staff_cannot_download_evidence(): void {
        $staff=$this->u('staff@cspc.edu.ph');
        $this->actingAs($this->u('student@my.cspc.edu.ph'))->post('/concerns',[
            'category'=>'Academic','department'=>'College of Computer Studies',
            'description'=>'a complaint about the teacher with evidence',
            'about_staff_id'=>$staff->id,
            'attachments'=>[UploadedFile::fake()->create('evidence.jpg', 100, 'image/jpeg')],
        ]);
        $c=Concern::where('description','a complaint about the teacher with evidence')->firstOrFail();
        $a=$c->attachments()->first();
        $r=$this->actingAs($staff)->get("/concerns/{$c->id}/attachments/{$a->id}");
        $this->line("[authz] reported staff grabs evidence -> ".$r->getStatusCode()." (want 403)");
        $r->assertForbidden();
    }

    /** Cross-concern: attachment id from another concern returns 404 */
    public function test_cannot_access_attachment_via_wrong_concern(): void {
        $this->actingAs($this->u('student@my.cspc.edu.ph'))->post('/concerns',[
            'category'=>'Academic','department'=>'College of Computer Studies',
            'description'=>'concern A which carries the target attachment',
            'attachments'=>[UploadedFile::fake()->create('a.jpg', 100, 'image/jpeg')],
        ]);
        $cA=Concern::where('description','concern A which carries the target attachment')->firstOrFail();
        $a=$cA->attachments()->first();
        $cB=Concern::create(['user_id'=>$this->u('student@my.cspc.edu.ph')->id,'category'=>'Academic','department'=>'College of Computer Studies','description'=>'B','urgency'=>null,'status'=>'submitted','is_anonymous'=>false]);
        // try to fetch A's attachment through concern B's URL
        $r=$this->actingAs($this->u('student@my.cspc.edu.ph'))->get("/concerns/{$cB->id}/attachments/{$a->id}");
        $this->line("[authz] attachment via wrong concern -> ".$r->getStatusCode()." (want 404)");
        $r->assertNotFound();
    }
}