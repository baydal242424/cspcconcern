<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One entry in a concern's handling record.
 *
 * Written, never rewritten. The two columns on `concerns` hold the most
 * recent of each kind so the rest of the app can ask "what does this case
 * say now" in one read; this table is what the case has been through, and
 * nothing here is overwritten by a later save.
 */
class ConcernNote extends Model
{
    /** What was found while looking into it. */
    public const KIND_REVIEW = 'review';

    /** What is being done about it. */
    public const KIND_RESOLUTION = 'resolution';

    protected $fillable = [
        'concern_id',
        'user_id',
        'kind',
        'body',
    ];

    public function concern()
    {
        return $this->belongsTo(Concern::class);
    }

    /** Who wrote it. Null once their account has been removed. */
    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
