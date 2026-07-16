<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $fillable = [
        'user_id',
        'concern_id',
        'action',
        'changes',
        'description',
        'ip_address',
    ];

    /**
     * Get the user who performed the action.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the concern that was affected.
     */
    public function concern()
    {
        return $this->belongsTo(Concern::class);
    }
}
