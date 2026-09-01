<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Give a CSPC address a role from the command line.
 *
 * This exists because CSPC Mail is the only way in. Sign-in establishes WHO
 * someone is; it can never establish WHAT they are allowed to do, so every
 * Google sign-in lands as a Student. That leaves a bootstrap problem: the
 * Admin panel is the tool for assigning roles, but reaching it already
 * requires the Admin role. Somebody has to be made Admin from outside the
 * app, and this is that door.
 *
 * Use it once to create the first Admin. After that, roles are managed in
 * the UI at /admin/users -- which is auditable and does not require server
 * access, so prefer it for everything else.
 *
 *   php artisan user:role dean@cspc.edu.ph "Dean"
 *   php artisan user:role you@my.cspc.edu.ph Admin
 *
 * If the address has never signed in, --create makes a placeholder account
 * that activates on that person's first CSPC Mail sign-in.
 */
class AssignRole extends Command
{
    protected $signature = 'user:role
                            {email : The CSPC email address}
                            {role : Role name, e.g. Admin or "Guidance Counselor"}
                            {--create : Create the account if it does not exist yet}';

    protected $description = 'Assign a role to a CSPC account (bootstrap for the first Admin)';

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));
        $roleName = $this->argument('role');

        $role = Role::where('name', $roleName)->first();

        if (! $role) {
            $this->error("No such role: {$roleName}");
            $this->line('Available: '.Role::orderBy('name')->pluck('name')->implode(', '));

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            if (! $this->option('create')) {
                $this->error("No account for {$email}.");
                $this->line('Either ask them to sign in with CSPC Mail once, then re-run this,');
                $this->line('or pass --create to make a placeholder now.');

                return self::FAILURE;
            }

            // A placeholder: no google_id yet, so it stays dormant until that
            // person signs in with CSPC Mail, at which point the callback
            // matches on email and links their Google identity to this row --
            // keeping the role we set here.
            $user = User::create([
                'name' => $email,
                'email' => $email,
                // Random and never shared. There is no password login, so this
                // only exists to satisfy the non-null column.
                'password' => Hash::make(str()->random(40)),
                'role_id' => $role->id,
                'status' => 'approved',
            ]);

            $this->info("Created placeholder account for {$email} as {$role->name}.");
            $this->line('It activates the first time they sign in with CSPC Mail.');

            return self::SUCCESS;
        }

        $previous = optional($user->role)->name ?? 'no role';
        $user->forceFill(['role_id' => $role->id])->save();

        $this->info("{$user->email}: {$previous} -> {$role->name}");

        if (! $user->google_id) {
            $this->warn('Note: this account has never signed in with CSPC Mail, so it cannot be used yet.');
        }

        return self::SUCCESS;
    }
}
