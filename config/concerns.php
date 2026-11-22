<?php

/**
 * What this server will actually accept, in bytes.
 *
 * PHP writes these as "2M", "8M", "1G" or "-1" for no limit.
 */
$toBytes = static function (string $size): int {
    $size = trim($size);

    if ($size === '' || $size === '-1') {
        return PHP_INT_MAX;
    }

    $unit = strtolower(substr($size, -1));
    $number = (int) $size;

    return match ($unit) {
        'g' => $number * 1024 * 1024 * 1024,
        'm' => $number * 1024 * 1024,
        'k' => $number * 1024,
        default => $number,
    };
};

/**
 * The real ceiling: the lowest of the two PHP applies.
 *
 * upload_max_filesize caps one file; post_max_size caps the whole request,
 * form fields included, so it binds first whenever it is the smaller. Both
 * are refused by PHP before Laravel is reached -- the request simply arrives
 * empty -- so a bigger number in this file buys nothing and produces a blank
 * page where a message should be.
 */
$ceiling = min(
    $toBytes((string) ini_get('upload_max_filesize')),
    $toBytes((string) ini_get('post_max_size'))
);

return [

    /*
    |--------------------------------------------------------------------------
    | Evidence attachments
    |--------------------------------------------------------------------------
    |
    | Taken from the server rather than written down, because a number written
    | down is a promise the server does not keep. "5 MB each" was on the form
    | for months while PHP was set to 2M: a 3 MB file was refused before
    | Laravel was reached, and the student saw a blank page rather than the
    | message explaining why.
    |
    | Reading it back means the form, the validation rule and the error text
    | all say the same true thing on whatever machine this runs on -- the 2M
    | of a default php.ini, the 40M of this XAMPP, or whatever the host allows
    | in production.
    |
    | To raise it, raise upload_max_filesize AND post_max_size in php.ini and
    | restart the server; this follows. CONCERN_MAX_ATTACHMENT_MB can only
    | lower it further, never past what PHP will take.
    |
    */

    'max_attachment_mb' => max(1, (int) min(
        (int) floor($ceiling / 1048576),
        (int) env('CONCERN_MAX_ATTACHMENT_MB', PHP_INT_MAX)
    )),

    'max_attachments' => (int) env('CONCERN_MAX_ATTACHMENTS', 5),

    /*
    |--------------------------------------------------------------------------
    | How long a description may be
    |--------------------------------------------------------------------------
    |
    | In characters, not bytes. The column is TEXT, which holds 65,535 BYTES,
    | and a character outside ASCII costs up to four of them -- so the useful
    | ceiling is about 16,000 characters, not 65,535. Six thousand leaves the
    | whole of that headroom unused even if every character is an emoji.
    |
    | Here rather than written out at each site. The number appeared in five
    | places -- the filing rule, the draft rule, the textarea's maxlength, the
    | counter's starting text and the counter's script -- and a limit that is
    | written down five times is a limit that disagrees with itself the first
    | time somebody changes four of them.
    |
    | The floor is 20 characters and lives with the rules; it is about whether
    | a sentence says anything, not about what the server can hold.
    |
    */

    /*
     * The second argument is the DEFAULT: what this is when nothing sets
     * CONCERN_MAX_DESCRIPTION. It stood at 6,000 while .env said 500,000,
     * which meant the two disagreed everywhere .env had not been written
     * by hand -- a fresh clone, a new server, or the day somebody deletes
     * that line. The system would have dropped to 6,000 silently, and a
     * student who could not finish a sentence would have had no way to
     * find out why.
     *
     * 6,000 was a ceiling that belonged to the old TEXT column, 65,535
     * BYTES, where a long report with accented letters came within a few
     * thousand of silent truncation. The columns are MEDIUMTEXT now, 16
     * MB, so the only real limit is MySQL's 1 MiB max_allowed_packet --
     * the whole statement, not just the text. 500,000 leaves half of it
     * free.
     */
    'max_description' => (int) env('CONCERN_MAX_DESCRIPTION', 500000),

    /*
    | What a student may attach.
    |
    | Broad on purpose -- a photograph of an injury, a phone video of a
    | hazard, a screenshot, a scanned letter, a recording of a conversation.
    |
    | Not literally anything. Programs and scripts are left out: these files
    | are written to disk under names the uploader does not choose and served
    | back through a controller, so one arriving today is not executable, but
    | accepting them means a single mistake in how they are stored or served
    | later turns the evidence folder into a place to put malware. Nothing a
    | student needs to send to report a concern is an executable.
    */

    'attachment_extensions' => [
        // Pictures
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', 'bmp', 'tiff',
        // Video
        'mp4', 'mov', 'm4v', 'avi', 'mkv', 'webm', '3gp',
        // Sound
        'mp3', 'm4a', 'aac', 'wav', 'ogg', 'amr',
        // Documents
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf', 'csv',
        // Bundles of the above
        'zip',
    ],

];
