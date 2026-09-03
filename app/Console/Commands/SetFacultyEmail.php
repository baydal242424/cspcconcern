<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Replaces a placeholder address with a real one, in place.
 *
 * Faculty were seeded under @placeholder.cspc.edu.ph so routing had somebody
 * in every college before anyone had signed in. Their real addresses arrive
 * one at a time, and the wrong way to apply one is to create a new account:
 * the person would end up with two rows, and every concern already routed to
 * the placeholder would stay on the one nobody uses.
 *
 * Updating the address on the existing row keeps their role, college,
 * programme, concerns and audit history, and means their next Google sign-in
 * matches the account they already have.
 *
 *     php artisan faculty:email "Wenceslao Gavina" engrgavina@cspc.edu.ph
 */
class SetFacultyEmail extends Command
{
    protected $signature = 'faculty:email
                            {name : Any part of the name, e.g. "Gavina"}
                            {email : The confirmed CSPC address}';

    protected $description = 'Replace a placeholder email with a confirmed one, keeping the account';

    public function handle(): int
    {
        $search = $this->argument('name');
        $email = strtolower(trim($this->argument('email')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("'{$email}' is not a valid email address.");

            return self::FAILURE;
        }

        // Somebody else already holds it -- almost always a typo, and applying
        // it would collide on the unique index anyway.
        $taken = User::where('email', $email)->first();

        if ($taken && ! str_contains($taken->name, $search)) {
            $this->error("{$email} already belongs to {$taken->name}.");

            return self::FAILURE;
        }

        $matches = User::where('name', 'like', '%'.$search.'%')->with('role')->get();

        if ($matches->isEmpty()) {
            $this->error("No account matches '{$search}'.");

            return self::FAILURE;
        }

        // Never guess between two people. A wrong pick here quietly hands one
        // person's concerns to another.
        if ($matches->count() > 1) {
            $this->error("'{$search}' matches ".$matches->count().' accounts:');
            foreach ($matches as $m) {
                $this->line("  {$m->name} <{$m->email}>");
            }
            $this->line('Use more of the name.');

            return self::FAILURE;
        }

        $user = $matches->first();
        $was = $user->email;

        if ($was === $email) {
            $this->info("{$user->name} already has that address.");

            return self::SUCCESS;
        }

        $user->forceFill(['email' => $email])->save();

        $this->info("{$user->name}: {$was} -> {$email}");
        $this->line('  role:    '.(optional($user->role)->name ?? 'none'));
        $this->line('  college: '.($user->department ?: 'not set'));

        return self::SUCCESS;
    }
}
