<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Evidence file attached to a concern. Stored on the private 'local' disk
 * under a randomized path; original_name is only a display label. Downloads
 * must go through ConcernController::downloadAttachment (it re-checks the
 * viewer's authorization) -- never link to the stored path directly.
 */
class Attachment extends Model
{
    protected $fillable = [
        'concern_id',
        'uploaded_by',
        'original_name',
        'stored_path',
        'mime_type',
        'size_bytes',
    ];

    public function concern()
    {
        return $this->belongsTo(Concern::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Human-readable size, e.g. "1.2 MB". */
    public function humanSize(): string
    {
        $b = $this->size_bytes;
        if ($b >= 1048576) return round($b / 1048576, 1) . ' MB';
        if ($b >= 1024) return round($b / 1024) . ' KB';
        return $b . ' B';
    }

    /** Whether this attachment is a viewable image. */
    public function isImage(): bool
    {
        return in_array($this->mime_type, ['image/jpeg', 'image/png'], true);
    }
}