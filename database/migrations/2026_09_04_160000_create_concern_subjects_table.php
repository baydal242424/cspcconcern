<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a concern name more than one person as its subject.
 *
 * concerns.about_staff_id holds exactly one, and that was a hole rather than a
 * limitation. The whole purpose of naming someone is to route the concern AWAY
 * from them and wall them out of reading it. A student whose complaint was
 * about two instructors could name one -- and the other stayed fully eligible
 * to receive it, open it, and write the resolution on a complaint about
 * themselves. The form gave no hint of that; it simply had one dropdown.
 *
 * Every exclusion in the system reads the subject set, so the fix has to be a
 * set: routeConcern(), findHandler(), the referral picker, and the hard
 * conflict-of-interest wrapper in Concern::scopeVisibleTo().
 *
 * about_staff_id stays, holding the FIRST subject. It is derived, never
 * authoritative -- Concern::syncSubjects() is the only thing that writes it,
 * so the two cannot drift. It is kept because a nullable foreign key on the
 * row itself is what the existing data, the show page and a dozen tests are
 * written against, and because "is this concern about anybody" stays a column
 * read rather than a join.
 *
 * Deleting a user removes their pivot rows, matching about_staff_id's
 * nullOnDelete: an account that no longer exists cannot be walled out of
 * anything, and leaving orphan rows would hide concerns from live staff who
 * happened to inherit the id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('concern_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('concern_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            // One person is named on a concern once. Without this a repeated
            // submit could list somebody twice and the show page would print
            // their name twice.
            $table->unique(['concern_id', 'user_id']);

            // scopeVisibleTo asks "is this user a subject of this concern" on
            // every listing, for every staff member, on every page.
            $table->index('user_id');
        });

        // Carry across every concern that already names somebody, so the
        // exclusions keep working for existing complaints the moment the read
        // paths switch to the pivot.
        $existing = DB::table('concerns')
            ->whereNotNull('about_staff_id')
            ->select('id', 'about_staff_id')
            ->get();

        $now = now();

        foreach ($existing->chunk(500) as $chunk) {
            DB::table('concern_subjects')->insert(
                $chunk->map(fn ($row) => [
                    'concern_id' => $row->id,
                    'user_id' => $row->about_staff_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all()
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('concern_subjects');
    }
};
