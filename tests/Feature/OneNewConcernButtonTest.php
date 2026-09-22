<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One "+ New Concern" button on the student's list, never two.
 *
 * With nothing filed, the empty state carries its own button, and the one in
 * the header beside it read as a second, different action -- students in the
 * survey asked which to press.
 */
class OneNewConcernButtonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    private function student(): User
    {
        $student = User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();
        $student->forceFill([
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Technology',
            'section' => '3A',
        ])->save();

        return $student->refresh();
    }

    public function test_with_no_concerns_there_is_one_button(): void
    {
        $html = $this->actingAs($this->student())->get('/concerns')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '+ New Concern'));
        $this->assertStringContainsString("You haven't submitted any concerns yet", html_entity_decode($html, ENT_QUOTES));

        fwrite(STDERR, "  [list] no concerns: one New Concern button, in the empty state\n");
    }

    public function test_with_concerns_the_button_moves_to_the_header(): void
    {
        $student = $this->student();

        Concern::create([
            'user_id' => $student->id,
            'category' => 'Academic',
            'department' => 'College of Computer Studies',
            'description' => 'A concern so the list is not empty.',
            'status' => 'submitted',
            'is_anonymous' => false,
        ]);

        $html = $this->actingAs($student)->get('/concerns')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '+ New Concern'));
        $this->assertStringNotContainsString("You haven't submitted any concerns yet", html_entity_decode($html, ENT_QUOTES));

        fwrite(STDERR, "  [list] with concerns: one New Concern button, in the header\n");
    }
}
