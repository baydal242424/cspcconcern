<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User; use App\Models\Concern;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Database\Seeders\RoleSeeder; use Database\Seeders\UserSeeder;
class NullRoleAttackTest extends TestCase {
    use RefreshDatabase;
    protected function setUp():void{parent::setUp();$this->seed([RoleSeeder::class,UserSeeder::class]);}
    public function test_null_role_user_does_not_500_anywhere(): void {
        $nr=User::create(['name'=>'NR','email'=>'nr@cspc.edu','password'=>bcrypt('x'),'role_id'=>null]);
        $c=Concern::create(['user_id'=>User::where('email','student@cspc.edu')->first()->id,'category'=>'Academic','department'=>'College of Computer Studies','description'=>'x','urgency'=>null,'status'=>'submitted','is_anonymous'=>false]);
        foreach(['/concerns',"/concerns/{$c->id}","/concerns/{$c->id}/edit",'/dashboard'] as $url){
            $r=$this->actingAs($nr)->get($url);
            fwrite(STDERR,"  [null-role] GET $url -> ".$r->getStatusCode()."\n");
            $this->assertNotEquals(500,$r->getStatusCode(),"500 at $url");
        }
        $r=$this->actingAs($nr)->patch("/concerns/{$c->id}",['status'=>'resolved','urgency'=>'Low']);
        fwrite(STDERR,"  [null-role] PATCH update -> ".$r->getStatusCode()."\n");
        $this->assertNotEquals(500,$r->getStatusCode());
    }
}