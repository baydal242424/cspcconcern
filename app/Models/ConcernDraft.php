<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A concern somebody started writing and has not filed.
 *
 * Not a Concern. Nothing routes it, counts it, or can read it but the
 * student who wrote it -- which is why it lives in its own table rather
 * than as a status on concerns, where every queue and every dashboard
 * query would have to remember to leave it out.
 *
 * One per student. Saving again overwrites.
 */
class ConcernDraft extends Model
{
    protected $fillable = [
        'user_id',
        'category',
        'subcategory',
        'other_category',
        'description',
        'is_anonymous',
        'skip_adviser',
        'about_staff_id',
    ];

    protected $casts = [
        'is_anonymous' => 'boolean',
        'skip_adviser' => 'boolean',
        'about_staff_id' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * What to call this draft in the folder.
     *
     * A row needs something to be recognised by, and a draft has no
     * title: the student never gave it one, and asking for one would be
     * a field to fill in before they can save the thing they are saving
     * because they have not finished. So it is named after what it is
     * about -- the category and sub-category they picked -- and falls
     * back to the first words they wrote.
     */
    public function label(): string
    {
        $parts = array_filter([
            $this->category ? Concern::categoryLabel($this->category) : null,
            $this->subcategory,
        ]);

        if ($parts !== []) {
            return implode(' · ', $parts);
        }

        $words = trim((string) ($this->other_category ?: $this->description));

        return $words === '' ? 'Untitled draft' : \Illuminate\Support\Str::limit($words, 48);
    }

    /** The opening of what they wrote, for the line under the label. */
    public function preview(): string
    {
        return \Illuminate\Support\Str::limit(trim((string) $this->description), 110);
    }

    /**
     * Whether there is anything here worth coming back to.
     *
     * A draft saved from an untouched form is noise: the student is
     * offered "you have a saved draft" on a page that would look exactly
     * the same without it. Ticking a box alone does not count either --
     * a category with no words under it tells them nothing they did not
     * already know when they picked it.
     */
    public function hasContent(): bool
    {
        return trim((string) $this->description) !== ''
            || trim((string) $this->other_category) !== '';
    }

    /**
     * The form values this draft puts back, shaped like old() input.
     *
     * @return array<string, mixed>
     */
    public function toFormInput(): array
    {
        return [
            'category' => $this->category,
            'subcategory' => $this->subcategory,
            'other_category' => $this->other_category,
            'description' => $this->description,
            'is_anonymous' => $this->is_anonymous ? '1' : null,
            'skip_adviser' => $this->skip_adviser ? '1' : null,
            'about_staff_id' => $this->about_staff_id ?? [],
        ];
    }
}
