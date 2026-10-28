<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'concern_id',
        'title',
        'message',
        'is_read',
        'read_at',
        'email_sent',
    ];

    protected $casts = [
        'read_at' => 'datetime',
        'is_read' => 'boolean',
        'email_sent' => 'boolean',
    ];

    /**
     * Get the user who receives this notification.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the concern associated with this notification.
     */
    public function concern()
    {
        return $this->belongsTo(Concern::class);
    }

    /**
     * "16m", "2d", "1w" -- the age, as short as it can be written.
     *
     * diffForHumans() gives "16 minutes ago", which at this width wraps
     * onto a second line under every row and pushes the list twice as
     * long as it needs to be. The exact time is in the title attribute
     * for anybody who wants it.
     */
    public function shortAge(): string
    {
        $seconds = max(0, $this->created_at->diffInSeconds(now()));

        foreach ([
            [31536000, 'y'],
            [2592000, 'mo'],
            [604800, 'w'],
            [86400, 'd'],
            [3600, 'h'],
            [60, 'm'],
        ] as [$size, $suffix]) {
            if ($seconds >= $size) {
                return intdiv($seconds, $size).$suffix;
            }
        }

        return 'now';
    }

    /**
     * Whether this belongs under "New" rather than "Earlier".
     *
     * A day, because that is the window in which somebody is still
     * expecting the thing they are being told about. Older than that and
     * the notice is history they are scrolling back through.
     */
    public function isRecent(): bool
    {
        return $this->created_at->greaterThan(now()->subDay());
    }
}
