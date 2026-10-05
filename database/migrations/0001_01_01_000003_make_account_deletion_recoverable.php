<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deleting an account stops destroying it on the spot.
 *
 * An administrator clicking Delete used to remove the row outright, and the
 * foreign key on concerns.user_id cascaded every concern that person had ever
 * filed out of the database with it. There was no undo, and the mistake is an
 * easy one: the wrong row in a list of several hundred near-identical names.
 *
 * The row now stays, marked deleted. The account cannot sign in and is gone
 * from every list, but the concerns it owns are untouched -- the cascade never
 * fires, because nothing is deleted at the database level. Signing in again
 * puts the person back where they were.
 *
 * Dated to run immediately after the users table is created, rather than at
 * the end where it was written. Several later migrations reach for the User
 * model, and the model declares SoftDeletes: every query it builds asks for
 * deleted_at, so on a fresh database the column has to be there before any of
 * them run. It is written to be safe either way, because databases that
 * already migrated past that point will apply it late.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'deleted_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'deleted_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
