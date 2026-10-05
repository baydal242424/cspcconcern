<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A second hat.
 *
 * Most people hold one role. Some hold two -- an office staff member who also
 * covers Staff Admin -- and until now the only way to record that was to pick
 * one and lose the other.
 *
 * users.role_id stays as the PRIMARY role, and nothing that reads it changes:
 * it is what routing matches on when choosing a handler, and what the account
 * is listed as. This table holds the extra ones, which grant what they
 * normally grant -- what the person can read, and the pages they can open --
 * without changing who concerns are routed TO.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // One row per pair: granting the same role twice is not a thing,
            // and a duplicate would show the role twice in every list.
            $table->unique(['user_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_user');
    }
};
