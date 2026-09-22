<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\Section;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * See who is on file, from the terminal.
 *
 * The Manage Users page is the place to CHANGE anything -- it is auditable and
 * needs no server access. This is only for looking, which the page cannot do
 * when you are already in a terminal running user:add and want to know whether
 * an address is there, what role it ended up with, or which classes still have
 * no adviser.
 *
 *   php artisan user:list                     everyone, newest first
 *   php artisan user:list --roles             just the roles, and how many hold each
 *   php artisan user:list --role=Dean         everyone with one role
 *   php artisan user:list --search=baydal     by name, email, or ID number
 *   php artisan user:list --advisers          who advises which class this term
 *   php artisan user:list --pending           staff waiting for an admin to give them a role
 */
class ListUsers extends Command
{
    protected $signature = 'user:list
        {--roles : Show the roles and how many people hold each, then stop}
        {--role= : Only this role, e.g. Dean or "Program Chair"}
        {--college= : Only this college or office}
        {--search= : Match a name, email, student ID or employee ID}
        {--advisers : Only people who advise a class, with the classes they advise}
        {--pending : Only staff who have asked for a role and are still waiting}
        {--limit=40 : How many rows to print (0 for all)}';

    protected $description = 'List the accounts on file, with their roles';

    public function handle(): int
    {
        if ($this->option('roles')) {
            return $this->printRoles();
        }

        $query = User::with('role')->orderByDesc('id');

        if ($role = $this->option('role')) {
            if (! Role::where('name', $role)->exists()) {
                $this->error("No such role: {$role}");
                $this->line('Available: '.Role::orderBy('name')->pluck('name')->implode(', '));

                return self::FAILURE;
            }

            $query->whereHas('role', fn ($q) => $q->where('name', $role));
        }

        if ($college = $this->option('college')) {
            $query->where('department', $college);
        }

        if ($search = $this->option('search')) {
            $query->where(function ($q) use ($search) {
                foreach (['name', 'email', 'student_id', 'employee_id'] as $column) {
                    $q->orWhere($column, 'like', '%'.$search.'%');
                }
            });
        }

        if ($this->option('pending')) {
            $query->whereNotNull('requested_role_id')->with('requestedRole');
        }

        if ($this->option('advisers')) {
            $query->whereHas('advisedSections')->with('advisedSections');
        }

        $total = (clone $query)->count();
        $limit = (int) $this->option('limit');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $users = $query->get();

        if ($users->isEmpty()) {
            $this->warn('Nobody matches that.');

            return self::SUCCESS;
        }

        if ($this->option('advisers')) {
            $this->table(['Name', 'Email', 'Role', 'Advises'], $users->map(fn (User $u) => [
                $u->name,
                $u->email,
                optional($u->role)->name ?? '—',
                $u->advisedSections->map(fn ($s) => $s->course.' '.$s->section)->implode(', '),
            ])->all());
        } elseif ($this->option('pending')) {
            $this->table(['Name', 'Email', 'Role now', 'Asked for', 'Since'], $users->map(fn (User $u) => [
                $u->name,
                $u->email,
                optional($u->role)->name ?? '—',
                optional($u->requestedRole)->name ?? '—',
                optional($u->role_requested_at)->diffForHumans() ?? '—',
            ])->all());
        } else {
            $this->table(['Name', 'Email', 'Role', 'College / office', 'Programme', 'Class', 'Signed in'], $users->map(fn (User $u) => [
                $u->name,
                $u->email,
                optional($u->role)->name ?? '—',
                $u->department ?: '—',
                $u->course ?: '—',
                $u->section ?: '—',
                // A row that has never signed in is a placeholder waiting for
                // its person, not a working account.
                $u->google_id ? 'yes' : 'not yet',
            ])->all());
        }

        $shown = $users->count();
        $this->line($shown < $total
            ? "Showing {$shown} of {$total}. Pass --limit=0 for all of them."
            : ($total === 1 ? '1 account.' : "{$total} accounts."));

        return self::SUCCESS;
    }

    /** The roles themselves, with how many people hold each and who is missing. */
    private function printRoles(): int
    {
        $counts = User::selectRaw('role_id, count(*) as total')
            ->groupBy('role_id')
            ->pluck('total', 'role_id');

        $rows = Role::orderBy('name')->get()->map(fn (Role $role) => [
            $role->name,
            (int) ($counts[$role->id] ?? 0),
        ])->all();

        $noRole = User::whereNull('role_id')->count();

        if ($noRole > 0) {
            $rows[] = ['(no role)', $noRole];
        }

        $this->table(['Role', 'People'], $rows);

        $term = Section::currentTerm();
        $classes = Section::where('school_year', $term['school_year'])
            ->where('semester', $term['semester']);

        $this->line("Classes on record this term ({$term['school_year']}, {$term['semester']} semester): "
            .(clone $classes)->count()
            .', without an adviser: '.(clone $classes)->whereNull('adviser_id')->count());

        $this->line('See one role: php artisan user:list --role="Program Chair"');

        return self::SUCCESS;
    }
}
