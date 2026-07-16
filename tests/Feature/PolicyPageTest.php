<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Database\Seeders\RoleSeeder; use Database\Seeders\UserSeeder;
class PolicyPageTest extends TestCase {
    use RefreshDatabase;
    protected function setUp():void{parent::setUp();$this->seed([RoleSeeder::class,UserSeeder::class]);}
    private function line($t){fwrite(STDERR,"  $t\n");}

    public function test_policy_is_public_for_guests(): void {
        $r=$this->get('/policy');
        $this->line("[policy] guest -> ".$r->getStatusCode()." (want 200)");
        $r->assertOk();
        $r->assertSee('Data Privacy');
        $r->assertSee('Break-Glass');
    }
    public function test_policy_visible_when_logged_in(): void {
        $r=$this->actingAs(User::where('email','student@cspc.edu')->first())->get('/policy');
        $r->assertOk();
        $r->assertSee('Evidence Attachments');
        $this->line("[policy] student view -> 200, shows evidence + roles sections");
    }
}