<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A letter calling the person a concern names in to the office.
 *
 * Kept as a record rather than sent as a message: a letter has to be
 * reprintable. The student loses it, the office files a copy, and six
 * months later somebody asks what date they were called for.
 *
 * It holds when and where, and nothing about what. The person being
 * called cannot open the concern, and a letter that explained itself
 * would walk straight around that.
 */
class ConcernSummons extends Model
{
    // Laravel would look for "concern_summonses"; the plural of summons
    // is summons.
    protected $table = 'concern_summons';

    protected $fillable = [
        'concern_id',
        'user_id',
        'issued_by',
        'meet_at',
        'place',
        'note',
    ];

    protected $casts = [
        'meet_at' => 'datetime',
    ];

    public function concern()
    {
        return $this->belongsTo(Concern::class);
    }

    /** The person being called in. */
    public function person()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** The staff member who called them. */
    public function issuer()
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /** Whether the appointment has already passed. */
    public function isPast(): bool
    {
        return $this->meet_at->isPast();
    }
}
