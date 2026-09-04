<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which programmes an instructor teaches.
 *
 * The filing form could only group instructors by college, and a college is
 * not a useful unit for finding a person: Health Sciences has 170 of them.
 * A student who does not remember the name exactly -- which is most of them --
 * had 368 names and a search box, and searching only helps if you already know
 * what you are looking for.
 *
 * users.course could not answer this. It holds ONE programme and means "the
 * programme this person belongs to": the student's own, or the single
 * programme a Program Chair chairs. An instructor teaches several, and a
 * migration deliberately cleared course from every non-programme role. So the
 * link is its own table, many-to-many, rather than a column.
 *
 * Programmes are strings from User::COURSES_BY_COLLEGE rather than an id into
 * a programmes table, because that constant is already the single source of
 * truth for what CSPC offers -- every other programme field in the schema
 * stores the same strings, and a lookup table here would be a second list to
 * keep in step with the first.
 *
 * Instructors fill this in themselves, on /my-programmes. Nobody else knows
 * their teaching load, it changes every term, and an admin typing it for 368
 * people would be stale before they finished.
 *
 * Empty is a legitimate state, not a fault: a new instructor who has not
 * answered yet, or somebody who genuinely teaches across programmes. Those
 * people stay listed under their college, which is exactly where they were
 * before this table existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instructor_programmes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('course');
            $table->timestamps();

            // One instructor names a programme once.
            $table->unique(['user_id', 'course']);

            // The filing form asks "who teaches this programme" on every load.
            $table->index('course');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructor_programmes');
    }
};
