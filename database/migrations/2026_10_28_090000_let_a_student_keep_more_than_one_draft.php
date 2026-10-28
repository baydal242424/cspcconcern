<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * More than one draft each.
 *
 * One was the wrong shape. A student writing up an incident over several
 * sittings has no reason to be stopped from starting a second about
 * something else -- and while the limit was one, starting the second
 * silently overwrote the first. The Draft button is a folder, not a slot.
 *
 * The unique index cannot simply be dropped: MySQL refuses, because the
 * foreign key on the same column is using it ("needed in a foreign key
 * constraint"). So the key comes off first and goes back on afterwards.
 * SQLite has no such objection and rebuilds the table either way, but it
 * also names nothing the same, so the whole thing is done by Blueprint
 * rather than by raw SQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('concern_drafts', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('concern_drafts', function (Blueprint $table) {
            $table->dropUnique('concern_drafts_user_id_unique');

            // Still indexed: every read is "the drafts belonging to this
            // student", which is the whole access pattern.
            $table->index('user_id');
        });

        Schema::table('concern_drafts', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // Only one may survive the way back, so the newest is kept and the
        // rest are dropped -- otherwise the unique index cannot go back on.
        if (Schema::hasTable('concern_drafts')) {
            $keep = DB::table('concern_drafts')
                ->selectRaw('MAX(id) as id')
                ->groupBy('user_id')
                ->pluck('id');

            DB::table('concern_drafts')->whereNotIn('id', $keep)->delete();
        }

        Schema::table('concern_drafts', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('concern_drafts', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->unique('user_id');
        });

        Schema::table('concern_drafts', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
