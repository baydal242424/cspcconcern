<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A reporter's 1-5 rating (plus optional comment) on how their resolved
 * concern was handled. One per concern -- see the unique index on
 * concern_id. Left by the reporter only, only after the concern is
 * resolved (see ConcernController::storeFeedback()).
 *
 * @property int $id
 * @property int $concern_id
 * @property int $user_id
 * @property int $rating
 * @property string|null $comment
 */
class Feedback extends Model
{
    // Laravel treats "feedback" as uncountable, so it would look for a
    // "feedback" table -- the migration creates "feedbacks". Be explicit.
    protected $table = 'feedbacks';

    protected $fillable = [
        'concern_id',
        'user_id',
        'rating',
        'comment',
    ];

    public function concern()
    {
        return $this->belongsTo(Concern::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
