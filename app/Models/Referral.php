<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Referral extends Model
{
    protected $fillable = [
        'concern_id',
        'referred_by',
        'referred_to',
        'reason',
        'referral_notes',
        'status',
        'accepted_at',
        'closed_at',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    /**
     * Get the concern being referred.
     */
    public function concern()
    {
        return $this->belongsTo(Concern::class);
    }

    /**
     * Get the user who initiated the referral.
     */
    public function referredBy()
    {
        return $this->belongsTo(User::class, 'referred_by');
    }

    /**
     * Get the user who received the referral.
     */
    public function referredTo()
    {
        return $this->belongsTo(User::class, 'referred_to');
    }
}
