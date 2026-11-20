<?php

use App\Http\Controllers\ConcernController;
use App\Models\Concern;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Categories become rows, so the office can add one without a deployment.
 *
 * They were eleven names in a PHP constant, with four more constants hanging
 * off them -- the label a student reads, the sub-categories, the role that
 * handles it, the offices it may be referred to. Adding a twelfth meant
 * editing five lists in two files and shipping a release, which in practice
 * means it does not happen: a college that decides next year to take
 * complaints about, say, the canteen has to wait for whoever still has the
 * repository.
 *
 * The eleven are seeded HERE FROM THE CONSTANTS THEMSELVES rather than
 * retyped. Retyping them would have created a second copy to drift from the
 * first, and the whole point is that there is now one.
 *
 * Nothing is deleted. 'Administrative' still means a fault in this website
 * and 808 concerns still carry the name they were filed under: the stored
 * value is a contract, and this table holds the contract rather than
 * replacing it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('concern_categories', function (Blueprint $table) {
            $table->id();

            // The stored value, matched by name on every concern row. This
            // is the contract: renaming one would orphan the concerns that
            // carry it, so the interface offers no rename.
            $table->string('name')->unique();

            // What a student reads. Free to change at any time -- it is
            // words on a screen and nothing matches on it.
            $table->string('label');

            // Which role picks it up. Null means nobody in particular, and
            // routeConcern() falls back as it always did.
            $table->string('routing_role')->nullable();

            // The second dropdown on the filing form. Null for a category
            // that does not break down -- 'Others' is the standing example.
            $table->json('subcategories')->nullable();

            // Offered on the filing form. A category is retired by clearing
            // this, never by deleting the row: the concerns already filed
            // under it must keep reading correctly, and the staff filter
            // must keep finding them.
            $table->boolean('is_active')->default(true);

            // One of the eleven shipped with the system. Protected from
            // deletion, because routing, urgency grading and a dozen tests
            // match on these names.
            $table->boolean('is_builtin')->default(false);

            // The order the filing form offers them in.
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();
        });

        $order = 0;
        $now = now();
        $rows = [];

        foreach (Concern::CATEGORIES as $name) {
            $rows[] = [
                'name' => $name,
                'label' => Concern::CATEGORY_LABELS[$name] ?? $name,
                'routing_role' => ConcernController::CATEGORY_ROUTING[$name] ?? null,
                'subcategories' => isset(Concern::SUBCATEGORIES[$name])
                    ? json_encode(Concern::SUBCATEGORIES[$name])
                    : null,
                // The website fault keeps its row and its routing, but is
                // not offered in the picker: it is reached from the bug
                // link in the footer. Marked inactive is exactly that
                // distinction, and means the admin page shows it as the
                // retired-from-the-form category it already is.
                'is_active' => $name !== Concern::WEBSITE_FAULT,
                'is_builtin' => true,
                'sort_order' => $order += 10,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('concern_categories')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('concern_categories');
    }
};
