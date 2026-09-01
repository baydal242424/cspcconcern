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
 * @property string|null $investigation_notes
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

    /**
     * Non-academic units a concern can be filed against. The colleges are NOT
     * listed here -- they come from User::COURSES_BY_COLLEGE so the concern
     * form and the registration form can never drift apart. That matters:
     * routeConcern() matches a concern's department against users.department,
     * and a college spelled differently in the two lists would never match.
     *
     * @var list<string>
     */
    public const SUPPORT_OFFICES = [
        'Guidance Office',
        'SASO',
    ];

    /**
     * Every unit a concern can be filed against: all six CSPC colleges,
     * then the support offices.
     *
     * @return list<string>
     */
    public static function departments(): array
    {
        return array_merge(array_keys(User::COURSES_BY_COLLEGE), self::SUPPORT_OFFICES);
    }

    /**
     * The statuses that END a concern's life. Nothing more happens to a
     * concern in one of these: it drops out of the active list, staff can no
     * longer edit it, and it stops counting as an open case in visibility.
     *
     *  - resolved          : acted on, and the reporter can now rate it.
     *  - closed_no_action  : assessed and found not to be a valid complaint
     *                        (Student Handbook Ch. 9 §A.2). Requires a written
     *                        reason, which the reporter is shown.
     *
     * Kept as a list so "is this case still open?" is answered in one place --
     * it used to be spelled `status != 'resolved'` in six separate queries,
     * every one of which would have silently treated a closed concern as open.
     *
     * @var list<string>
     */
    public const TERMINAL_STATUSES = ['resolved', 'closed_no_action'];

    /**
     * How each status is written for a human. The views used to derive this
     * with ucfirst(str_replace('_',' ',...)), which is fine for "in_progress"
     * but renders 'closed_no_action' as the clumsy "Closed no action".
     *
     * @var array<string, string>
     */
    public const STATUS_LABELS = [
        'submitted' => 'Submitted',
        'in_progress' => 'In Progress',
        'referred' => 'Referred',
        'resolved' => 'Resolved',
        'closed_no_action' => 'Closed',
    ];

    /**
     * Human label for a raw status value, falling back to the old derivation
     * so an unmapped status never renders as an empty cell.
     */
    public static function label(?string $status): string
    {
        return self::STATUS_LABELS[$status]
            ?? ucfirst(str_replace('_', ' ', (string) $status));
    }

    /** Convenience for views: {{ $concern->status_label }}. */
    public function getStatusLabelAttribute(): string
    {
        return self::label($this->status);
    }

    protected $fillable = [
        'user_id',
        'category',
        'department',
        'urgency',
        'description',
        'investigation_notes',
        'status',
        'is_anonymous',
        'assigned_to',
        'about_staff_id',
        'referred_to',
        'identity_revealed_at',
        'identity_revealed_by',
        'identity_reveal_reason',
        'resolved_at',
        'closed_at',
        'closure_reason',
        'resolution_notes',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
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
     * The reporter's rating/comment on how this concern was resolved.
     * Null until the reporter leaves one -- only possible once resolved.
     */
    public function feedback()
    {
        return $this->hasOne(Feedback::class);
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
     *  - Dean    : same as Faculty/Staff.
     *  - Guidance Counselor : Mental Health & Bullying concerns, plus anything
     *                         referred to "Guidance Counselor".
     *  - Admin              : Administrative and Facilities / Equipment
     *                         concerns, plus anything referred to "Admin".
     *                         (Admin does NOT see confidential counselor
     *                         cases by default.)
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
                          ->whereNotIn('status', self::TERMINAL_STATUSES);
                  })
                  // Currently assigned to her (open case she must act on).
                  ->orWhere(function ($sub) use ($user) {
                      $sub->where('assigned_to', $user->id)
                          ->whereNotIn('status', self::TERMINAL_STATUSES);
                  })
                  // Anything she has personally handled (history / reference).
                  ->orWhere($involved);
            });
        }

        // Gender and Development. Deliberately has NO category of its own:
        // unlike every other staff role below, GAD sees only what has been
        // explicitly referred or assigned to it, plus its own handling
        // history. A harassment concern still reaches the Guidance Counselor
        // first, who assesses it and refers it on if it is a CMO No. 3
        // sexual-harassment case. Giving GAD blanket access to the Bullying /
        // Harassment category instead would widen who can read those reports
        // by default, which is the opposite of what a referral gate is for.
        if ($role === 'Gender and Development') {
            return $query->where(function ($q) use ($user, $involved) {
                $q->where(function ($sub) {
                    $sub->where('referred_to', 'Gender and Development')
                        ->whereNotIn('status', self::TERMINAL_STATUSES);
                })
                  ->orWhere(function ($sub) use ($user) {
                      $sub->where('assigned_to', $user->id)
                          ->whereNotIn('status', self::TERMINAL_STATUSES);
                  })
                  ->orWhere($involved);
            });
        }

        // General Services. Facilities / Equipment is its natural domain, the
        // way Administrative is Admin's -- routeConcern() sends every one of
        // them here, so the office must be able to see them without waiting
        // for a referral.
        if ($role === 'General Services') {
            return $query->where(function ($q) use ($user, $involved) {
                $q->where('category', 'Facilities / Equipment')
                  ->orWhere(function ($sub) {
                      $sub->where('referred_to', 'General Services')
                          ->whereNotIn('status', self::TERMINAL_STATUSES);
                  })
                  ->orWhere(function ($sub) use ($user) {
                      $sub->where('assigned_to', $user->id)
                          ->whereNotIn('status', self::TERMINAL_STATUSES);
                  })
                  ->orWhere($involved);
            });
        }

        // The Registrar. Administrative is its natural domain -- enrolment,
        // records, credentials -- and routeConcern() sends every one here.
        if ($role === 'Registrar') {
            return $query->where(function ($q) use ($user, $involved) {
                $q->where('category', 'Administrative')
                  ->orWhere(function ($sub) {
                      $sub->where('referred_to', 'Registrar')
                          ->whereNotIn('status', self::TERMINAL_STATUSES);
                  })
                  ->orWhere(function ($sub) use ($user) {
                      $sub->where('assigned_to', $user->id)
                          ->whereNotIn('status', self::TERMINAL_STATUSES);
                  })
                  ->orWhere($involved);
            });
        }

        // Admin now has NO category of its own. Administrative went to the
        // Registrar and Facilities / Equipment to General Services -- the
        // offices that can actually act on them. That is deliberate: "Admin"
        // here means the people who administer the SYSTEM (accounts, roles,
        // bans, the dashboard), and giving them a standing window into
        // students' concerns is exactly the privilege least-privilege is meant
        // to withhold. They still see anything explicitly referred or assigned
        // to them, plus their own handling history.
        if ($role === 'Admin') {
            return $query->where(function ($q) use ($user, $involved) {
                $q->whereRaw('1 = 0')
                  ->orWhere(function ($sub) {
                      $sub->where('referred_to', 'Admin')
                          ->whereNotIn('status', self::TERMINAL_STATUSES);
                  })
                  ->orWhere(function ($sub) use ($user) {
                      $sub->where('assigned_to', $user->id)
                          ->whereNotIn('status', self::TERMINAL_STATUSES);
                  })
                  ->orWhere($involved);
            });
        }

        // Faculty/Staff, Program Chair and Dean all work the same
        // academic queue -- the chair sits between the other two in authority,
        // not in what they may read, so splitting the rule would only create a
        // gap where an escalated concern is visible to neither.
        if (in_array($role, ['Faculty/Staff', 'Program Chair', 'Dean'], true)) {
            return $query->where(function ($q) use ($user, $role, $involved) {
                $q->where('assigned_to', $user->id)
                  ->orWhere(function ($sub) use ($role) {
                      $sub->where('referred_to', $role)
                          ->whereNotIn('status', self::TERMINAL_STATUSES);
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