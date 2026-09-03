<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A class section and the faculty member who advises it.
 *
 * Versioned by school year and semester, because the assignment changes every
 * term and last term's record is history rather than something to overwrite.
 */
class Section extends Model
{
    protected $fillable = [
        'course',
        'section',
        'school_year',
        'semester',
        'adviser_id',
    ];

    public function adviser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adviser_id');
    }

    /**
     * The adviser for a given course and section, from the most recent term
     * that has a record of it.
     *
     * Latest rather than "current" on purpose: nothing in the system knows
     * today's school year, and inventing a rule for it would go wrong every
     * August. The newest record that names an adviser is the best answer
     * available, and a college that has not published this term's assignments
     * keeps reaching last term's adviser instead of nobody.
     */
    public static function adviserFor(?string $course, ?string $section): ?User
    {
        if (blank($course) || blank($section)) {
            return null;
        }

        return static::query()
            ->where('course', $course)
            ->where('section', $section)
            ->whereNotNull('adviser_id')
            ->orderByDesc('school_year')
            ->orderByDesc('semester')
            ->orderByDesc('id')
            ->with('adviser')
            ->first()
            ?->adviser;
    }
}
