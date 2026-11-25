<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Academic cases from BS Information Systems 4A go to their class adviser,
 * Glaiza Pocaan -- including the ones already filed.
 *
 * For a stretch of the demonstration set-up the section was advised by a
 * demo account, first an Instructor and then a demo Adviser, and a concern
 * filed in that window stayed with it after the section moved. Routing
 * decides where a NEW concern lands; it never re-homes an old one. So a
 * student's academic case sat with "Demo Instructor -- Instructor", the
 * one kind of account the panel ruled out as a destination.
 *
 * This is a migration rather than a console step because the deployed
 * site runs migrations on every deploy. Nobody has to remember a command.
 *
 * WHAT MOVES. Open Academic and Others concerns from BSIS 4A held by a
 * `demo.` account or by anybody whose role is Instructor. Nothing settled
 * moves -- the record says who handled those, and it is true -- and
 * nothing already with a real adviser, chair or dean is touched.
 *
 * Glaiza Pocaan is in no seeder: her account was made the first time she
 * signed in with Google and promoted by hand. So she is created as an
 * Adviser where she is missing, and promoted where she is a Student.
 *
 * On an empty database -- the test suite's -- there is no Adviser role yet
 * when this runs, and it does nothing.
 */
return new class extends Migration
{
    private const COURSE = 'BS Information Systems';

    private const SECTION = '4A';

    private const ADVISER_EMAIL = 'glpocaan@my.cspc.edu.ph';

    private const DONE = ['resolved', 'closed_no_action', 'withdrawn'];

    public function up(): void
    {
        $adviserRole = DB::table('roles')->where('name', 'Adviser')->value('id');

        if (! $adviserRole) {
            return;
        }

        // FIRST, whether there is anything to move. Only then is anything
        // created or re-pointed.
        //
        // The first version set up the account and the section before
        // looking, and the test database has the Adviser role by the time
        // this runs -- earlier migrations insert roles -- so every test got
        // a Glaiza Pocaan advising BS Information Systems 4A, and twelve of
        // them watched an academic case go to her instead of the adviser
        // they had set up. A data fix acts on the data it was written for
        // and on nothing else.
        $instructorRole = DB::table('roles')->where('name', 'Instructor')->value('id');

        $wrongDesks = DB::table('users')
            ->where('email', 'like', 'demo.%')
            ->when($instructorRole, fn ($q) => $q->orWhere('role_id', $instructorRole))
            ->pluck('id');

        $live = DB::table('concerns')
            ->whereIn('category', ['Academic', 'Others'])
            ->where('course', self::COURSE)
            ->where('section', self::SECTION)
            ->whereNotIn('status', self::DONE)
            ->whereNull('deleted_at')
            ->whereIn('assigned_to', $wrongDesks)
            ->pluck('id');

        if ($live->isEmpty()) {
            return;
        }

        $now = now();

        // Her account: found, restored, promoted -- or made.
        $glaiza = DB::table('users')->where('email', self::ADVISER_EMAIL)->first();

        if ($glaiza) {
            DB::table('users')->where('id', $glaiza->id)->update([
                'role_id' => $adviserRole,
                'status' => 'approved',
                'deleted_at' => null,
                'updated_at' => $now,
            ]);
            $glaizaId = $glaiza->id;
        } else {
            $glaizaId = DB::table('users')->insertGetId([
                'name' => 'Glaiza Pocaan',
                'email' => self::ADVISER_EMAIL,
                'password' => bcrypt(Str::random(32)),
                'role_id' => $adviserRole,
                'department' => 'College of Computer Studies',
                'course' => self::COURSE,
                'status' => 'approved',
                'email_verified_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // The section points at her, so every NEW academic case does too.
        $section = DB::table('sections')
            ->where('course', self::COURSE)
            ->where('section', self::SECTION)
            ->orderByDesc('school_year')
            ->orderByDesc('semester')
            ->orderByDesc('id')
            ->first();

        if ($section) {
            DB::table('sections')->where('id', $section->id)->update([
                'adviser_id' => $glaizaId,
                'updated_at' => $now,
            ]);
        } else {
            $term = \App\Models\Section::currentTerm();
            DB::table('sections')->insert([
                'course' => self::COURSE,
                'section' => self::SECTION,
                'school_year' => $term['school_year'],
                'semester' => $term['semester'],
                'adviser_id' => $glaizaId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // And the cases already filed, which routing never re-homes.
        DB::table('concerns')->whereIn('id', $live)->update([
            'assigned_to' => $glaizaId,
            'updated_at' => $now,
        ]);

        // Written as a referral because that is what the student sees
        // happen: the case changed desks, and the timeline reads hand-offs.
        // audit_logs.user_id is NOT NULL, and this is the administration's
        // decision, so the System Admin signs where there is one.
        $actor = DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.name', 'System Admin')
            ->whereNull('users.deleted_at')
            ->value('users.id') ?? $glaizaId;

        DB::table('audit_logs')->insert(
            $live->map(fn ($id) => [
                'user_id' => $actor,
                'concern_id' => $id,
                'action' => 'status_updated',
                'description' => 'Referred to Adviser',
                'created_at' => $now,
                'updated_at' => $now,
            ])->all()
        );
    }

    public function down(): void
    {
        // Nothing to undo. Who holds a case is a fact about who is working
        // it, and by the time anybody runs this the adviser may have done
        // exactly that.
    }
};
