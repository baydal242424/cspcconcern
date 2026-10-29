<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calling in the person a concern names.
 *
 * The panel asked for two things once a complaint is acted on: the person
 * named is told it is being looked into -- which the system already does,
 * without disclosing who complained -- and then, when the office is ready
 * to deal with it, they are given a letter asking them to come in.
 *
 * A row rather than a one-off notification, because a letter has to be
 * reprintable: the student loses it, the office files a copy, somebody
 * asks months later what date they were called for. A notification is a
 * message that has been read or not; this is a record of a summons.
 *
 * It does NOT hold why. The reason sits in the concern, which the person
 * being called cannot open -- and putting it here would route around that
 * wall by way of a letter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('concern_summons', function (Blueprint $table) {
            $table->id();

            $table->foreignId('concern_id')->constrained()->cascadeOnDelete();

            // Who is being called. Cascades: with the account gone there is
            // nobody to call, and the letter names somebody who no longer
            // exists.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Who called them, kept for the signature line and so the
            // record says which office issued it.
            $table->foreignId('issued_by')->constrained('users')->cascadeOnDelete();

            $table->dateTime('meet_at');
            $table->string('place');

            // Anything practical: bring your ID, come to the side door.
            // Not the substance of the complaint.
            $table->string('note', 300)->nullable();

            $table->timestamps();

            $table->index(['user_id', 'meet_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('concern_summons');
    }
};
