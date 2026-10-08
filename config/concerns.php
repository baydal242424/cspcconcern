<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Evidence attachments
    |--------------------------------------------------------------------------
    |
    | The app's own limit. It is NOT the only one that applies: PHP refuses an
    | upload larger than upload_max_filesize before Laravel ever sees it, and
    | a host usually caps the request body at its edge well below that. Raising
    | the number here alone changes nothing -- it only stops the app rejecting
    | what the server already accepted.
    |
    | Local XAMPP ships with upload_max_filesize=2M and post_max_size=8M, which
    | is why "5 MB each" was never true: a 3 MB file was refused by PHP and the
    | student saw an empty page rather than the message.
    |
    */

    'max_attachment_mb' => (int) env('CONCERN_MAX_ATTACHMENT_MB', 1024),

    'max_attachments' => (int) env('CONCERN_MAX_ATTACHMENTS', 5),

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
