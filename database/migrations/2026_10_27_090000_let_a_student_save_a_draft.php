<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Somewhere to keep a concern that is not ready to be filed.
 *
 * A table of its own rather than a 'draft' status on concerns. A concern
 * row is read by a dozen queries -- routing, the dashboard counts, every
 * office's queue, scopeVisibleTo -- and a draft must appear in none of
 * them. Relying on every one of those to remember to exclude a status
 * would mean a half-written accusation naming somebody is one forgotten
 * `where` away from landing on a staff queue. Here there is nothing to
 * forget: no office can reach this table at all.
 *
 * One per student, enforced by the unique index. "Save and come back to
 * it" is the whole need; a list of drafts would be another page to build
 * and another thing to keep tidy.
 *
 * Attachments are deliberately not kept. They hang off concern_id, and
 * giving them a nullable draft_id as well would mean orphaned files to
 * sweep up for every draft ever abandoned. A student re-picks their files
 * when they come back, which costs them a few seconds and costs the
 * system no cleanup it can get wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('concern_drafts', function (Blueprint $table) {
            $table->id();

            // Unique: one draft each. Cascade, because a draft belongs to
            // nobody once the account is gone -- unlike a filed concern,
            // which stays as a record.
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // Every field is nullable. That is the point of a draft: it is
            // saved precisely because it is not finished, so the validation
            // the filing form applies cannot apply here.
            $table->string('category')->nullable();
            $table->string('subcategory')->nullable();
            $table->string('other_category')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_anonymous')->default(false);
            $table->boolean('skip_adviser')->default(false);

            // The people the concern is about, as chosen on the form. JSON
            // rather than a pivot: concern_subjects is keyed to a real
            // concern, and these ids are not a relationship yet -- they are
            // boxes somebody ticked and may untick.
            $table->json('about_staff_id')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('concern_drafts');
    }
};
