<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Somewhere to keep a setting the office can change without a deployment.
 *
 * The first one is the test-account sign-in. It lived in the environment,
 * which meant turning it on for a demonstration was a hosting-panel edit
 * and a redeploy -- work only the person who set the site up can do, at the
 * moment they are least able to: the morning of the demonstration.
 *
 * A row instead. The environment variable stays as the DEFAULT, so a fresh
 * deploy is still off unless somebody says otherwise, and the row wins once
 * it exists.
 *
 * KEY IS THE PRIMARY KEY, deliberately. A setting is named, not numbered,
 * and a second row for the same name is a bug rather than a thing to merge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();

            // Nullable text rather than a boolean column: the next setting
            // after this one will not be a flag, and a table that has to be
            // altered for every new kind of value is the thing this avoids.
            $table->text('value')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
