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
    /**
     * What a student may file, in the order the form offers them.
     *
     * Stored on the row and matched by name in routing, urgency grading and
     * every visibility rule -- a contract rather than a label. Renaming one
     * means migrating the concerns that carry it.
     */
    public const CATEGORIES = [
        'Academic',
        'Mental Health',
        'Personal',
        'Bullying',
        'Harassment',
        'Administrative',
        'Facilities',
        'Equipment',
        'Physical',
        'Safety',
        'Others',
    ];

    /** Assessed by the Guidance Office, and withheld from everybody else. */
    public const GUIDANCE_CATEGORIES = [
        'Mental Health',
        'Personal',
        'Bullying',
        'Harassment',
    ];

    /** Maintenance work: the General Services Unit's standing domain. */
    public const FACILITIES_CATEGORIES = [
        'Facilities',
        'Equipment',
    ];

    /**
     * The open queue the teaching tiers share. A concern here stays visible to
     * every instructor, chair and dean until somebody takes it, so nothing
     * sits unread while one person is away.
     */
    public const TEACHING_CATEGORIES = [
        'Academic',
        'Physical',
        'Safety',
        'Others',
    ];

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
        // Only set when category is Others -- what the student called it.
        'other_category',
        'department',
        'course',
        'section',
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
     * The FIRST person this concern is about. Derived from subjects(), which
     * is the authoritative list -- see syncSubjects().
     */
    public function aboutStaff()
    {
        return $this->belongsTo(User::class, 'about_staff_id');
    }

    /**
     * Everyone this concern is about.
     *
     * A concern can name several people, and it must: a complaint about two
     * instructors that could only name one left the other free to receive it,
     * read it, and resolve a complaint about themselves.
     */
    public function subjects()
    {
        return $this->belongsToMany(User::class, 'concern_subjects')->withTimestamps();
    }

    /**
     * The ids every exclusion rule works from.
     *
     * Falls back to about_staff_id for a concern built in memory that has not
     * been saved yet, where the pivot cannot exist.
     *
     * @return array<int, int>
     */
    public function subjectIds(): array
    {
        // about_staff_id is included as well as the pivot, never instead of
        // it. syncSubjects() keeps the two in step, but a concern written
        // straight to the table -- a seeder, a fixture, a future controller
        // that sets the column and forgets the list -- would otherwise have a
        // named subject that no exclusion could see, and that person stays
        // free to receive and read the complaint about themselves. Reading
        // both means the only way to lose the wall is to name nobody.
        $ids = [(int) $this->about_staff_id];

        if ($this->exists) {
            $ids = array_merge($ids, $this->relationLoaded('subjects')
                ? $this->subjects->pluck('id')->all()
                : $this->subjects()->pluck('users.id')->all());
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    /**
     * The only place the subject list is written.
     *
     * Sets the pivot AND about_staff_id together so the derived column cannot
     * drift from the list it is derived from -- a divergence here would mean a
     * person walled out of a concern by one rule and handed it by another.
     *
     * @param  array<int, int|string>  $ids
     */
    public function syncSubjects(array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        $this->subjects()->sync($ids);
        $this->setRelation('subjects', $this->subjects()->get());

        // The first named person, kept for the show page and for the cheap
        // "is this about anybody at all" check.
        $this->forceFill(['about_staff_id' => $ids[0] ?? null])->save();
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
        //
        // Reads the subject LIST, not about_staff_id. A concern can name
        // several people; checking only the first would wall out one of two
        // reported instructors and leave the other reading the complaint
        // against them both.
        // Both the list and the column, for the reason given on subjectIds():
        // a concern whose subject was written straight to about_staff_id has
        // no pivot row, and a pivot-only check would let that person read the
        // complaint about themselves.
        $query->where(function ($outer) use ($user) {
            $outer->whereNull('about_staff_id')
                ->orWhere('about_staff_id', '!=', $user->id);
        });

        $query->whereDoesntHave('subjects', function ($subject) use ($user) {
            $subject->where('users.id', $user->id);
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
                $q->whereIn('category', self::GUIDANCE_CATEGORIES)
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

        // General Services. Facilities and Equipment are its natural domain, the
        // way Administrative is Admin's -- routeConcern() sends every one of
        // them here, so the office must be able to see them without waiting
        // for a referral.
        if ($role === 'General Services') {
            return $query->where(function ($q) use ($user, $involved) {
                $q->whereIn('category', self::FACILITIES_CATEGORIES)
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

        // The administrative office. Administrative concerns -- enrolment,
        // records, ID, clearance, fees -- route here, so it has to be able to
        // SEE that category: a concern assigned to an office that cannot read
        // it is worse than one nobody was assigned, because the queue looks
        // handled.
        //
        // That is the whole of its window. Facilities still goes to General
        // Services, and everything confidential -- mental health, harassment --
        // stays out of reach unless a counsellor deliberately refers it here.
        if ($role === 'Staff Admin') {
            return $query->where(function ($q) use ($user, $involved) {
                $q->where('category', 'Administrative')
                  ->orWhere(function ($sub) {
                      $sub->where('referred_to', 'Staff Admin')
                          ->whereNotIn('status', self::TERMINAL_STATUSES);
                  })
                  ->orWhere(function ($sub) use ($user) {
                      $sub->where('assigned_to', $user->id)
                          ->whereNotIn('status', self::TERMINAL_STATUSES);
                  })
                  ->orWhere($involved);
            });
        }

        // The people who run the system get NO standing window into any
        // category. They see what is assigned or referred to them and nothing
        // else -- which for a System Admin is usually a complaint that climbed
        // past the administrative office because it was about that office.
        //
        // The single Admin role used to read every Administrative concern,
        // because it was also the office that handled them. Splitting the two
        // removed the reason: managing accounts and roles needs no view of
        // students' complaints, and this is the narrowest the role has ever
        // been.
        if ($role === 'System Admin') {
            return $query->where(function ($q) use ($user, $involved) {
                $q->where(function ($sub) {
                    $sub->where('referred_to', 'System Admin')
                        ->whereNotIn('status', self::TERMINAL_STATUSES);
                })
                  ->orWhere(function ($sub) use ($user) {
                      $sub->where('assigned_to', $user->id)
                          ->whereNotIn('status', self::TERMINAL_STATUSES);
                  })
                  ->orWhere($involved);
            });
        }

        // Instructor, Program Chair and Dean all work the same academic queue --
        // the chair sits between the other two in authority, not in what they
        // may read, so splitting the rule would only create a gap where an
        // escalated concern is visible to neither.
        //
        // Faculty/Staff is in this list for what it can still reach: anything
        // assigned or referred to it, and its own history. It no longer sees
        // the open academic queue, because nothing routes there any more --
        // office staff were being shown every academic complaint in the college
        // on the strength of sharing a role name with the teachers.
        // Referral-gated, both of them. The VPAA oversees the Administration
        // rather than working a queue, so she sees what is escalated or
        // referred to her and nothing else -- an oversight role with a standing
        // window into every student's concern would be the opposite of the
        // point.
        // Referral-gated. Instructor is here rather than on the academic queue
        // because Academic, Physical, Safety and Others now reach the Adviser
        // first -- an instructor works what is sent to them, and what routing
        // falls back to them when a college has named no adviser yet.
        if (in_array($role, ['Instructor', 'Faculty/Staff', 'Vice President for Academic Affairs'], true)) {
            return $query->where(function ($q) use ($user, $role, $involved) {
                $q->where('assigned_to', $user->id)
                  ->orWhere(function ($sub) use ($role) {
                      $sub->where('referred_to', $role)
                          ->whereNotIn('status', self::TERMINAL_STATUSES);
                  })
                  ->orWhere($involved);
            });
        }

        if (in_array($role, ['Adviser', 'Program Chair', 'Dean'], true)) {
            return $query->where(function ($q) use ($user, $role, $involved) {
                $q->where('assigned_to', $user->id)
                  ->orWhere(function ($sub) use ($role) {
                      $sub->where('referred_to', $role)
                          ->whereNotIn('status', self::TERMINAL_STATUSES);
                  })
                  ->orWhere(function ($sub) {
                      $sub->where('status', 'submitted')
                          ->whereIn('category', self::TEACHING_CATEGORIES);
                  })
                  // Anything they have personally handled (history / reference).
                  ->orWhere($involved);
            });
        }

        // Unknown role: see nothing.
        return $query->whereRaw('1 = 0');
    }
}