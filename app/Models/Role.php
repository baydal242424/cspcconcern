<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $fillable = ['name', 'description'];

    /**
     * What a role is CALLED on screen, where that differs from the value
     * stored on the row.
     *
     * The stored name is a contract: 34 places in the application and 15
     * in the tests match 'System Admin' by name -- routing, the guard
     * that decides who may appoint an administrator, visibility, the
     * escalation chain. Renaming the row would mean touching every one of
     * them and migrating every user, every referral and every audit entry
     * that carries the string.
     *
     * The label is words on a screen and nothing matches on it. Same
     * split as Concern::CATEGORY_LABELS, where 'Administrative' is stored
     * and "System Problem" is shown.
     *
     * @var array<string, string>
     */
    public const LABELS = [
        // "System Admin" and "Staff Admin" read as two of a kind, which
        // they are not: one of them appoints the other and can ban it,
        // and that asymmetry is the whole point of having two tiers.
        // "Super Admin" says which one holds the keys.
        'System Admin' => 'Super Admin',
    ];

    /** What to show for a role name. */
    public static function label(?string $name): string
    {
        return self::LABELS[$name] ?? (string) $name;
    }

    /** Convenience for views: {{ $user->role->label }}. */
    public function getLabelAttribute(): string
    {
        return self::label($this->name);
    }

    /**
     * Get all users with this role.
     */
    public function users()
    {
        return $this->hasMany(User::class);
    }
}
