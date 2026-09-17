<?php

namespace Tests\Feature;

use App\Models\Concern;
use App\Models\User;
use Database\Seeders\Faculty\CcsFacultySeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The concern page shows the programme and year & section it was filed from.
 *
 * They decided which class adviser the concern reached, so the handler needs
 * them. But a section is a class of forty, and beside an anonymous submission
 * it would all but name the student -- so it follows the same rule as the
 * reporter's name.
 */
class ConcernShowsSectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class, CcsFacultySeeder::class]);
    }

    private function student(): User
    {
        return User::where('email', 'student@my.cspc.edu.ph')->firstOrFail();
    }

    private function handler(): User
    {
        return User::where('email', 'jeremyneo@cspc.edu.ph')->firstOrFail();
    }

    private function concern(array $overrides = []): Concern
    {
        return Concern::create(array_merge([
            'user_id' => $this->student()->id,
            'category' => 'Academic',
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Technology',
            'section' => '1F',
            'description' => 'A concern used to check what the details panel shows.',
            'status' => 'submitted',
            'is_anonymous' => false,
            'assigned_to' => $this->handler()->id,
        ], $overrides));
    }

    public function test_the_handler_sees_the_programme_and_section(): void
    {
        $c = $this->concern();

        $this->actingAs($this->handler())->get("/concerns/{$c->id}")
            ->assertOk()
            ->assertSee('<dt>Program</dt>', false)
            ->assertSee('Year 1 &middot; Section F', false);

        fwrite(STDERR, "  [details] handler sees BS Information Technology, Year 1 Section F: YES\n");
    }

    public function test_an_anonymous_concern_withholds_them_from_the_handler(): void
    {
        $c = $this->concern(['is_anonymous' => true]);

        $this->actingAs($this->handler())->get("/concerns/{$c->id}")
            ->assertOk()
            ->assertDontSee('<dt>Program</dt>', false)
            ->assertDontSee('Year 1 &middot; Section F', false)
            ->assertSee('Withheld');

        fwrite(STDERR, "  [privacy] anonymous: programme and section withheld from the handler: YES\n");
    }

    public function test_the_reporter_still_sees_their_own(): void
    {
        $c = $this->concern(['is_anonymous' => true]);

        $this->actingAs($this->student())->get("/concerns/{$c->id}")
            ->assertOk()
            ->assertSee('Year 1 &middot; Section F', false);

        fwrite(STDERR, "  [details] reporter sees their own section on an anonymous concern: YES\n");
    }

    /**
     * Names alone do not say who is holding the concern. The role and the
     * college sit beside both the handler and the person it is about, so the
     * panel can be read without looking anybody up.
     */
    public function test_the_panel_names_the_role_and_college_of_both_people(): void
    {
        $chair = User::create([
            'name' => 'The Chair',
            'email' => 'chair.details@cspc.edu.ph',
            'password' => \Illuminate\Support\Facades\Hash::make('not-used'),
            'role_id' => \App\Models\Role::where('name', 'Program Chair')->firstOrFail()->id,
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Technology',
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);

        $c = $this->concern(['about_staff_id' => $chair->id]);

        $this->actingAs($this->handler())->get("/concerns/{$c->id}")
            ->assertOk()
            // The handler: role and college.
            ->assertSee('Instructor · College of Computer Studies', false)
            // The person it is about: role, college and the programme they chair.
            ->assertSee('Program Chair · College of Computer Studies · BS Information Technology', false);

        fwrite(STDERR, "  [details] role and college shown for the handler and the subject: YES\n");
    }

    /**
     * Advising is a section assignment, not a role, so the class adviser of a
     * section shows as "Instructor" -- next to a concern that reached them
     * BECAUSE they advise it. The panel says which one they are.
     */
    public function test_the_class_adviser_of_the_section_is_labelled_as_one(): void
    {
        $handler = $this->handler();

        \App\Models\Section::create([
            'course' => 'BS Information Technology',
            'section' => '1F',
            'school_year' => '2024-2025',
            'semester' => 'Second',
            'adviser_id' => $handler->id,
        ]);

        $c = $this->concern();

        $this->actingAs($handler)->get("/concerns/{$c->id}")
            ->assertOk()
            // Their role gives way to it: "Class adviser · Instructor" read as
            // though two people were meant.
            ->assertSee('Class adviser · College of Computer Studies', false)
            ->assertDontSee('Class adviser · Instructor', false);

        fwrite(STDERR, "  [details] the section's adviser is labelled Class adviser, not just Instructor: YES\n");
    }

    /**
     * The referral picker groups people by office, so the class adviser sits
     * among the instructors -- the one name in there with a standing claim on
     * this concern. It is marked, for the same reason as the panel above.
     */
    public function test_the_referral_picker_marks_the_class_adviser(): void
    {
        $adviser = User::create([
            'name' => 'The Section Adviser',
            'email' => 'section.adviser.picker@cspc.edu.ph',
            'password' => \Illuminate\Support\Facades\Hash::make('not-used'),
            'role_id' => \App\Models\Role::where('name', 'Instructor')->firstOrFail()->id,
            'department' => 'College of Computer Studies',
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);

        \App\Models\Section::create([
            'course' => 'BS Information Technology',
            'section' => '1F',
            'school_year' => '2024-2025',
            'semester' => 'Second',
            'adviser_id' => $adviser->id,
        ]);

        $c = $this->concern();

        $this->actingAs($this->handler())->get("/concerns/{$c->id}")
            ->assertOk()
            ->assertSee('The Section Adviser — College of Computer Studies · class adviser', false);

        fwrite(STDERR, "  [details] the referral list marks which instructor is the class adviser: YES\n");
    }
}
