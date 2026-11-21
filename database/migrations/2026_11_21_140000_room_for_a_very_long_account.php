<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Room for a description of half a million characters.
 *
 * TEXT holds 65,535 BYTES -- not characters. In utf8mb4 that is anywhere
 * between 16,000 and 65,000 characters depending on what is typed, and the
 * application was letting people type 60,000, which on a report with
 * accented letters in it was already within a few thousand of the ceiling.
 *
 * MEDIUMTEXT holds 16,777,215 bytes. At the worst case of four bytes per
 * character, 500,000 characters is 2 MB -- an eighth of the column.
 *
 * WHY NOT A MILLION, WHICH IS WHAT WAS ASKED FOR.
 *
 * The column could hold it; the wire cannot. MySQL's max_allowed_packet is
 * 1 MiB here, and that is the whole statement -- the text, the column
 * names, the other values, the escaping. A million ASCII characters is
 * 1,000,000 bytes before any of that, so the insert would fail outright
 * with "got a packet bigger than max_allowed_packet". Raising it is a
 * server setting that does not travel with this repository and would have
 * to be set again on the deployed host, which is exactly the kind of
 * invisible dependency that works locally and breaks in front of a panel.
 *
 * 500,000 leaves half the packet free, and is 2,500 times the longest
 * description anyone has actually filed (193 characters).
 *
 * The three sibling columns move with it. A handler answering a
 * half-million-character account needs somewhere to answer it.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'description',
        'investigation_notes',
        'resolution_notes',
        'closure_reason',
    ];

    public function up(): void
    {
        // MySQL and MariaDB only.
        //
        // MEDIUMTEXT and MODIFY are both MySQL spellings, and the test
        // suite runs on SQLite -- where this threw a syntax error on
        // every single test, because a migration that cannot run is a
        // database that cannot be built. SQLite's TEXT has no length
        // limit of its own, so there is nothing for this to do there.
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        // Raw ALTER rather than Schema::table()->mediumText()->change():
        // change() needs doctrine/dbal and rewrites the column from the
        // Blueprint's idea of it, which quietly drops anything the
        // Blueprint does not know about. This touches the type and
        // nothing else.
        foreach (self::COLUMNS as $column) {
            if (Schema::hasColumn('concerns', $column)) {
                DB::statement("ALTER TABLE `concerns` MODIFY `{$column}` MEDIUMTEXT NULL");
            }
        }

        if (Schema::hasColumn('concern_notes', 'body')) {
            DB::statement('ALTER TABLE `concern_notes` MODIFY `body` MEDIUMTEXT NOT NULL');
        }
    }

    public function down(): void
    {
        // Deliberately NOT narrowed back. A column shrunk from MEDIUMTEXT
        // to TEXT truncates every row that has outgrown it -- silently, on
        // a database that is not in strict mode, which this one is not.
        // Rolling a migration back should not be able to destroy what
        // people wrote.
    }
};
