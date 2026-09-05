<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The administrative office: the accounts that receive Administrative
 * concerns -- enrolment, records, ID, clearance, fees.
 *
 * Split out of the single Admin role, which had been doing two unrelated jobs
 * at once. Whoever could delete accounts and change roles was also reading
 * every complaint about the registrar's queue.
 *
 * No email address is published for these posts, so each account is created
 * under @placeholder.cspc.edu.ph -- a subdomain that does not exist, which is
 * the point: it cannot silently deliver anywhere. Replace it once confirmed:
 *
 *     php artisan faculty:email "Ibo" hisaddress@cspc.edu.ph
 *
 * That updates the row in place, so the person's role, concerns and audit
 * history stay attached. Creating a second account instead would strand
 * everything already routed to the first.
 *
 * Not called from DatabaseSeeder. Run it deliberately:
 *
 *     php artisan db:seed --class=Database\\Seeders\\StaffAdminSeeder
 */
class StaffAdminSeeder extends Seeder
{
    /**
     * [name, office]. Taken from the college's published staff listing.
     *
     * @var list<array{0:string, 1:string}>
     */
    private const STAFF = [
        // Instructor I, Web and Social Media Administrator. He administers the
        // college's own web presence, which is the nearest real counterpart to
        // this system's administrative office.
        ['Allan O. Ibo, Jr., MSc.', 'Information and Communications Technology Unit'],
    ];

    public function run(): void
    {
        $role = Role::where('name', 'Staff Admin')->first();

        if (! $role) {
            $this->command?->warn("The 'Staff Admin' role does not exist; run the migrations first.");

            return;
        }

        $created = 0;
        $skipped = 0;

        foreach (self::STAFF as [$name, $office]) {
            // Match on first name AND surname rather than the whole string.
            //
            // Allan O. Ibo, Jr. was already in the database as "Mr. Allan O.
            // Ibo Jr." from the CCS faculty list, and an exact-name check
            // missed him: the honorific, the comma and the MSc. all differ.
            // The address then collided anyway -- both reduce to allan.ibo@ --
            // so firstOrCreate found the instructor row, changed nothing, and
            // reported a success. He stayed an Instructor, and the Staff Admin
            // role stayed empty with nothing to say why.
            $existing = $this->findExisting($name);

            if ($existing) {
                $existing->forceFill(['role_id' => $role->id, 'department' => $office])->save();
                $skipped++;

                continue;
            }

            User::firstOrCreate(
                ['email' => $this->placeholderAddress($name)],
                [
                    'name' => $name,
                    'password' => Hash::make(Str::random(40)),
                    'role_id' => $role->id,
                    'department' => $office,
                    'status' => 'approved',
                    // Google sign-in is the only way in, and it proves control
                    // of the mailbox. Without this the account is bounced by
                    // the auth middleware before it can be assigned anything.
                    'email_verified_at' => now(),
                ]
            );

            $created++;
        }

        $this->command?->info("Staff Admin: {$created} seeded, {$skipped} already present and moved into the role.");
        $this->command?->warn('Placeholder addresses do not deliver. Replace them with php artisan faculty:email as they are confirmed.');
    }

    /**
     * The same person already on file, however their name was written there.
     *
     * Both names are reduced to first name plus surname, so "Allan O. Ibo,
     * Jr., MSc." finds "Mr. Allan O. Ibo Jr.". Surname alone would be too
     * loose -- CSPC has several people sharing one.
     */
    private function findExisting(string $name): ?User
    {
        $wanted = $this->nameKey($name);

        return User::all()->first(fn (User $u) => $this->nameKey($u->name) === $wanted);
    }

    /** "Mr. Allan O. Ibo Jr." and "Allan O. Ibo, Jr., MSc." both give "allan ibo". */
    private function nameKey(string $name): string
    {
        $parts = $this->nameParts($name);

        return strtolower(($parts[0] ?? '').' '.(end($parts) ?: ''));
    }

    /**
     * first.last@placeholder.cspc.edu.ph, with the honorifics and suffixes
     * stripped so the address stays readable.
     */
    private function placeholderAddress(string $name): string
    {
        $parts = $this->nameParts($name);

        return Str::slug($parts[0] ?? 'staff').'.'.Str::slug(end($parts) ?: 'admin')
            .'@placeholder.cspc.edu.ph';
    }

    /**
     * A name reduced to its real words: honorifics, suffixes, middle initials
     * and post-nominals removed.
     *
     * @return list<string>
     */
    private function nameParts(string $name): array
    {
        $clean = preg_replace('/,.*$/', '', $name);
        $clean = preg_replace('/\b(Jr|Sr|II|III|IV|MSc|MA|MS|MBA|PhD|EdD|Dr|Engr|Atty|Ms|Mr|Mrs)\b\.?/i', '', $clean);

        // Keep only tokens that are actually words. Stripping "Mr." leaves a
        // bare full stop behind, and a single initial is not part of anybody's
        // identity -- without this filter "Mr. Allan O. Ibo Jr." reduced to
        // ". ." and matched nothing.
        $parts = preg_split('/\s+/', trim($clean));
        $parts = array_filter($parts, fn ($p) => preg_match('/^[A-Za-z][A-Za-z\'\-]+$/', $p));

        return array_values($parts);
    }
}
