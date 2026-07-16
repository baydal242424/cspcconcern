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
}
