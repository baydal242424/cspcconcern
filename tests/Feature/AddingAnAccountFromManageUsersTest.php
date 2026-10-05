<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Adding an account from the page, rather than from a terminal.
 *
 * `php artisan user:add` has done this since the start, which is useful to
 * whoever runs the server and no use at all to the administrator sitting in
 * front of Manage Users. The rules it enforces are not decoration -- they are
 * what decides whether a concern ever reaches anybody -- so they are enforced
 * here too rather than trusted to the form.
 */
class AddingAnAccountFromManageUsersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, UserSeeder::class]);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@cspc.edu.ph')->firstOrFail();
    }

    private function studentRoleId(): int
    {
        return Role::where('name', 'Student')->firstOrFail()->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Juan Dela Cruz',
            'email' => 'juan.new@my.cspc.edu.ph',
            'role_id' => $this->studentRoleId(),
            'department' => 'College of Computer Studies',
            'course' => 'BS Information Systems',
            'year' => '3',
            'section_letter' => 'B',
            'student_id' => '231004321',
        ], $overrides);
    }

    /** The panel is on the page. */
    public function test_the_panel_is_rendered(): void
    {
        $this->actingAs($this->admin())->get('/admin/users')->assertOk()
            ->assertSee('Add an account')
            ->assertSee('name="email"', false)
            ->assertSee(route('admin.users.store'), false);

        fwrite(STDERR, "  [add] the panel is on Manage Users\n");
    }

    /** A whole account, with the fields routing actually reads. */
    public function test_an_admin_can_add_an_account(): void
    {
        $this->actingAs($this->admin())->from('/admin/users')
            ->post('/admin/users', $this->payload())
            ->assertRedirect('/admin/users')
            ->assertSessionHasNoErrors();

        $user = User::where('email', 'juan.new@my.cspc.edu.ph')->firstOrFail();

        $this->assertSame('Juan Dela Cruz', $user->name);
        $this->assertSame('Student', $user->role->name);
        $this->assertSame('College of Computer Studies', $user->department);
        $this->assertSame('BS Information Systems', $user->course);
        $this->assertSame('3B', $user->section, 'year and class are joined the way routing reads them');
        $this->assertSame('231004321', $user->student_id);

        // Dormant: Google sign-in is the only door, and it has not been
        // through it yet.
        $this->assertNull($user->google_id);
        $this->assertNull($user->email_verified_at);
        $this->assertSame('approved', $user->status);

        fwrite(STDERR, "  [add] an account is created with the fields routing reads\n");
    }

    /** Only an administrator. */
    public function test_a_student_cannot_add_accounts(): void
    {
        $this->actingAs(User::where('email', 'student@my.cspc.edu.ph')->firstOrFail())
            ->post('/admin/users', $this->payload())
            ->assertForbidden();

        $this->assertNull(User::where('email', 'juan.new@my.cspc.edu.ph')->first());

        fwrite(STDERR, "  [add] a student cannot add accounts\n");
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function badPayloads(): array
    {
        return [
            'an address Google will turn away' => [['email' => 'juan@gmail.com'], 'email'],
            'a programme the college does not offer' => [['course' => 'BS Nursing'], 'course'],
            'a year the programme does not run to' => [['year' => '5'], 'year'],
            'a class with no year' => [['year' => ''], 'year'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badPayloads')]
    public function test_it_refuses_what_would_break_routing(array $overrides, string $field): void
    {
        $this->actingAs($this->admin())->from('/admin/users')
            ->post('/admin/users', $this->payload($overrides))
            ->assertSessionHasErrors($field);

        $this->assertNull(
            User::where('email', $this->payload($overrides)['email'])->first(),
            'nothing should have been created'
        );

        fwrite(STDERR, "  [add] refused: {$field}\n");
    }

    /** An address or an ID number already in use is refused. */
    public function test_a_duplicate_address_or_id_number_is_refused(): void
    {
        $this->actingAs($this->admin())->post('/admin/users', $this->payload())->assertRedirect();

        // Same address.
        $this->actingAs($this->admin())->from('/admin/users')
            ->post('/admin/users', $this->payload(['student_id' => '231009999']))
            ->assertSessionHasErrors('email');

        // Same student number, different address.
        $this->actingAs($this->admin())->from('/admin/users')
            ->post('/admin/users', $this->payload(['email' => 'other.new@my.cspc.edu.ph']))
            ->assertSessionHasErrors('student_id');

        $this->assertSame(1, User::where('student_id', '231004321')->count());

        fwrite(STDERR, "  [add] a duplicate address or ID number is refused\n");
    }

    /**
     * A deleted account does not block the address.
     *
     * It parks its own when the person signs up again; an administrator
     * re-adding them by hand must not be stopped by a row nobody can see.
     */
    public function test_a_deleted_account_does_not_block_the_address(): void
    {
        $this->actingAs($this->admin())->post('/admin/users', $this->payload())->assertRedirect();

        $added = User::where('email', 'juan.new@my.cspc.edu.ph')->firstOrFail();
        $added->delete();

        $this->actingAs($this->admin())->from('/admin/users')
            ->post('/admin/users', $this->payload())
            ->assertSessionHasNoErrors();

        // One live account holds the address...
        $this->assertSame(1, User::where('email', 'juan.new@my.cspc.edu.ph')->count());

        // ...and the deleted row has parked its own, so it is still readable
        // as whose it was without standing in anybody's way.
        $this->assertStringStartsWith('deleted-', User::withTrashed()->find($added->id)->email);

        fwrite(STDERR, "  [add] a deleted account does not block the address\n");
    }
}
