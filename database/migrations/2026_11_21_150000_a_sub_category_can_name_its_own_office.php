<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sub-categories become rows of their own, so one can name its own office.
 *
 * They were a list of strings: ["Grades", "Subjects", "Enrollment"]. That
 * was enough while the whole category routed to one desk, and it stopped
 * being enough the moment somebody noticed that "Instructor conduct" and
 * "Class schedule" are both Academic and are not the same office's work --
 * one is a complaint about a person and the other is a timetable.
 *
 * Each becomes {"name": "...", "route": null}. A null route means "whatever
 * the category routes to", which is what every one of them does today, so
 * this migration changes the shape and not a single destination.
 *
 * Written to survive being run on a table somebody has already edited: a
 * row that is already in the new shape is left alone, so this is safe to
 * re-run and safe to meet half-converted data.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('concern_categories')->get() as $row) {
            $subs = json_decode($row->subcategories ?? 'null', true);

            if (! is_array($subs) || $subs === []) {
                continue;
            }

            $converted = [];

            foreach ($subs as $sub) {
                if (is_array($sub) && array_key_exists('name', $sub)) {
                    // Already converted. Keep whatever route it carries.
                    $converted[] = [
                        'name' => (string) $sub['name'],
                        'route' => $sub['route'] ?? null,
                    ];

                    continue;
                }

                $converted[] = ['name' => (string) $sub, 'route' => null];
            }

            DB::table('concern_categories')
                ->where('id', $row->id)
                ->update([
                    'subcategories' => json_encode($converted),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('concern_categories')->get() as $row) {
            $subs = json_decode($row->subcategories ?? 'null', true);

            if (! is_array($subs) || $subs === []) {
                continue;
            }

            $flat = array_map(
                fn ($sub) => is_array($sub) ? ($sub['name'] ?? '') : (string) $sub,
                $subs
            );

            DB::table('concern_categories')
                ->where('id', $row->id)
                ->update([
                    'subcategories' => json_encode(array_values(array_filter($flat))),
                    'updated_at' => now(),
                ]);
        }
    }
};
