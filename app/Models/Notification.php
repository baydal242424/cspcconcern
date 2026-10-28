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
     * How each kind of notice is drawn in the bell.
     *
     * A round mark and a colour, in place of the profile photo a social
     * app would use -- there are no photos here, and a column of
     * identical grey rows is the thing that makes a list of ten
     * unreadable at a glance. The colour carries the meaning: anything
     * needing the person to act is amber, anything about an outcome is
     * green, routine movement is blue.
     *
     * @var array<string, array{glyph: string, tint: string}>
     */
    private const LOOK = [
        'status_update' => ['glyph' => '↻', 'tint' => '#2f5bea'],
        'new_concern' => ['glyph' => '✱', 'tint' => '#7c3aed'],
        'named_in_concern' => ['glyph' => '!', 'tint' => '#b45309'],
        'notice_from_handler' => ['glyph' => '✉', 'tint' => '#b45309'],
        'documents_added' => ['glyph' => '↑', 'tint' => '#0f766e'],
        'reactivation_request' => ['glyph' => '⟳', 'tint' => '#0369a1'],
        'account_reactivated' => ['glyph' => '✓', 'tint' => '#0f766e'],
        'role_request' => ['glyph' => '⟳', 'tint' => '#0369a1'],
        'id_number_clash' => ['glyph' => '!', 'tint' => '#be123c'],
    ];

    /** The character shown in the round mark. */
    public function glyph(): string
    {
        return self::LOOK[$this->type]['glyph'] ?? '•';
    }

    /** Its colour. */
    public function tint(): string
    {
        return self::LOOK[$this->type]['tint'] ?? '#64748b';
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
