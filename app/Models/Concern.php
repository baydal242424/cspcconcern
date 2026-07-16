<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A student's concern report -- the central record of the system.
 * Attachments, audit logs, notifications and referrals all hang off it.
 * The important part of this file is scopeVisibleTo() at the bottom: the
 * single source of truth for who may see which concern. Access rules
 * change there, not in controllers or views.
 *
 * @property int $id
 * @property int $user_id
 * @property string $category
 * @property string $department
 * @property string $urgency
 * @property string $description
 * @property string|null $status
 * @property bool $is_anonymous
 * @property int|null $assigned_to
 * @property \Illuminate\Support\Carbon|null $resolved_at
 * @property string|null $resolution_notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @property-read User $user
 * @property-read User|null $assignedUser
 * @property-read \Illuminate\Database\Eloquent\Collection|Referral[] $referrals
 * @property-read \Illuminate\Database\Eloquent\Collection|Notification[] $notifications
 * @property-read \Illuminate\Database\Eloquent\Collection|AuditLog[] $auditLogs
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
class Concern extends Model
{
    // Deleting a concern only soft-deletes it: the row, its audit trail, and
    // its evidence metadata are preserved (see the add_soft_deletes migration).
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'category',
        'department',
        'urgency',
        'description',
        'status',
        'is_anonymous',
        'assigned_to',
        'about_staff_id',
        'referred_to',
        'identity_revealed_at',
        'identity_revealed_by',
        'identity_reveal_reason',
        'resolved_at',
        'resolution_notes',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'identity_revealed_at' => 'datetime',
        'is_anonymous' => 'boolean',
    ];

    /**
     * Get the user who submitted this concern.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the user assigned to this concern.
     */
    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Get the referrals for this concern.
     */
    public function referrals()
    {
        return $this->hasMany(Referral::class);
    }

    /**
     * Get the notifications for this concern.
     */
    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    /**
     * The staff/person this concern is ABOUT (conflict-of-interest flag).
     */
    public function aboutStaff()
    {
        return $this->belongsTo(User::class, 'about_staff_id');
    }

    /**
     * The Head of School who revealed the reporter's identity (break-glass).
     */
    public function identityRevealer()
    {
        return $this->belongsTo(User::class, 'identity_revealed_by');
    }

    /**
     * Whether this concern's reporter identity has been revealed (break-glass).
     */
    public function identityIsRevealed(): bool
    {
        return $this->identity_revealed_at !== null;
    }

    /**
     * Get the audit logs for this concern.
     */
    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class);
    }

    /**
     * Evidence files attached to this concern.
     */
    public function attachments()
    {
        return $this->hasMany(Attachment::class);
    }

    /**
     * Scope a query to only the concerns a given user is permitted to see.
     *
     * This is the SINGLE SOURCE OF TRUTH for concern visibility. Both the
     * concern list and the dashboard use it, so they can never disagree.
     *
     * Rules (least-privilege by category + explicit referral):
     *  - Student            : only their own concerns.
     *  - Faculty/Staff      : concerns assigned to them, plus untriaged
     *                         submissions in their categories, plus anything
     *                         explicitly referred to "Faculty/Staff".
     *  - Department Head    : same as Faculty/Staff.
     *  - Guidance Counselor : Mental Health & Bullying concerns, plus anything
     *                         referred to "Guidance Counselor".
     *  - Admin              : Administrative concerns, plus anything referred
     *                         to "Admin". (Admin does NOT see confidential
     *                         counselor cases by default.)
     */
    public function scopeVisibleTo($query, User $user)
    {
        $role = optional($user->role)->name;

        if ($role === null) {
            // No role: see nothing.
            return $query->whereRaw('1 = 0');
        }

        if ($role === 'Student') {
            return $query->where('user_id', $user->id);
        }

        // HARD CONFLICT-OF-INTEREST EXCLUSION: a staff-type user must NEVER be
        // able to see a concern that is *about them*, by any path. This wraps
        // every rule below -- INCLUDING the Head of School's read-everything
        // rule -- so it cannot be bypassed via category, assignment, referral,
        // involvement history, or rank. The reported person is fully walled
        // off from the complaint against them.
        $query->where(function ($outer) use ($user) {
            $outer->whereNull('about_staff_id')
                  ->orWhere('about_staff_id', '!=', $user->id);
        });

        // Head of School: highest authority. Can read ALL concern CONTENT so
        // they can adjudicate escalations and suspected false reports. They do
        // NOT see reporter identities by default -- that requires an explicit,
        // logged break-glass reveal (see ConcernController@revealIdentity).
        if ($role === 'Head of School') {
            return $query;
        }

        // "Involvement history": a staff-type user stays able to see any concern
        // they have personally acted on (submitted, triaged, referred, resolved),
        // because every such action writes an audit log row with their user_id.
        // This keeps handled concerns in their list/dashboard as a reference even
        // after the concern is referred away or resolved -- without ever granting
        // access to concerns they never touched.
        $involved = function ($sub) use ($user) {
            $sub->whereHas('auditLogs', function ($log) use ($user) {
                $log->where('user_id', $user->id);
            });
        };

        if ($role === 'Guidance Counselor') {
            return $query->where(function ($q) use ($user, $involved) {
                // Natural domain: always visible.
                $q->whereIn('category', ['Mental Health / Personal', 'Bullying / Harassment'])
                  // Currently referred to her (open case she must act on).
                  ->orWhere(function ($sub) {
                      $sub->where('referred_to', 'Guidance Counselor')
                          ->where('status', '!=', 'resolved');
                  })
                  // Currently assigned to her (open case she must act on).
                  ->orWhere(function ($sub) use ($user) {
                      $sub->where('assigned_to', $user->id)
                          ->where('status', '!=', 'resolved');
                  })
                  // Anything she has personally handled (history / reference).
                  ->orWhere($involved);
            });
        }

        if ($role === 'Admin') {
            return $query->where(function ($q) use ($user, $involved) {
                $q->where('category', 'Administrative')
                  ->orWhere(function ($sub) {
                      $sub->where('referred_to', 'Admin')
                          ->where('status', '!=', 'resolved');
                  })
                  ->orWhere(function ($sub) use ($user) {
                      $sub->where('assigned_to', $user->id)
                          ->where('status', '!=', 'resolved');
                  })
                  ->orWhere($involved);
            });
        }

        // Faculty/Staff and Department Head
        if ($role === 'Faculty/Staff' || $role === 'Department Head') {
            return $query->where(function ($q) use ($user, $role, $involved) {
                $q->where('assigned_to', $user->id)
                  ->orWhere(function ($sub) use ($role) {
                      $sub->where('referred_to', $role)
                          ->where('status', '!=', 'resolved');
                  })
                  ->orWhere(function ($sub) {
                      $sub->where('status', 'submitted')
                          ->whereIn('category', ['Academic', 'Physical / Safety', 'Others']);
                  })
                  // Anything they have personally handled (history / reference).
                  ->orWhere($involved);
            });
        }

        // Unknown role: see nothing.
        return $query->whereRaw('1 = 0');
    }
}