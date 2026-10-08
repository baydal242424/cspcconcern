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

    /**
     * How a category is written for a student, where that differs from the
     * value stored on the row.
     *
     * The stored value is a contract: routing, urgency grading and every
     * visibility rule match it by name, so renaming one would mean migrating
     * every concern that carries it and touching twenty call sites. The label
     * is just words on a screen. Same split as STATUS_LABELS, where
     * 'closed_no_action' is stored and "Closed" is shown.
     *
     * @var array<string, string>
     */
    /**
     * What each category is CALLED on screen.
     *
     * The stored values below are the contract -- routing, the dashboard and
     * every test match on them -- so they never change. These are the names a
     * student reads, and several of the stored ones were too vague to choose
     * between: "Physical" could be a fight, a disability or a broken wall;
     * "Personal" could be anything at all; "Facilities" and "Equipment" both
     * sound like the right home for a dead lab computer.
     *
     * @var array<string, string>
     */
    public const CATEGORY_LABELS = [
        // Stored as 'Administrative' for historical reasons; it means a
        // fault in this website, and reaches the System Admin.
        'Administrative' => 'System Problem',
        'Personal' => 'Personal Problem',
        'Physical' => 'Physical Injury',
        'Safety' => 'Safety Hazard',
        'Facilities' => 'Building & Facilities',
        'Equipment' => 'Equipment & Devices',
    ];

    /**
     * A few words saying what each category covers, for the dropdown on the
     * filing form.
     *
     * One word per option is not enough to choose between eleven of them.
     * "Administrator" and "Facilities" and "Equipment" all sound like the
     * right home for a broken computer, and the full description only appeared
     * AFTER a category was picked -- so a student had to choose before they
     * could read what they were choosing.
     *
     * Short on purpose: a <select> cannot wrap, and the long version is still
     * shown under the field once something is selected.
     *
     * @var array<string, string>
     */
    /**
     * What the handler's own notes are called, per category.
     *
     * "Investigation Notes" was the label for all eleven. Most of them are
     * not investigations: a grade query is a record being checked, a dead
     * lab PC is a thing being inspected, and a counselling case is written
     * up. Bullying and Harassment keep the word, because there it is
     * accurate and the notes form part of a record that may be read back.
     *
     * The column, the field and the rule requiring it are unchanged -- this
     * is what it is CALLED, and a name that misdescribes the work invites
     * notes that misdescribe it too.
     */
    public const HANDLING_NOTE_LABELS = [
        'Academic' => 'Review Notes',
        'Mental Health' => 'Case Notes',
        'Personal' => 'Case Notes',
        'Bullying' => 'Investigation Notes',
        'Harassment' => 'Investigation Notes',
        'Administrative' => 'Diagnosis Notes',
        'Facilities' => 'Inspection Notes',
        'Equipment' => 'Inspection Notes',
        'Physical' => 'Assessment Notes',
        'Safety' => 'Assessment Notes',
        'Others' => 'Handling Notes',
    ];

    /**
     * The placeholder under each of those, asking for the right thing.
     */
    public const HANDLING_NOTE_HINTS = [
        'Academic' => 'What did you check, and what did the records or the instructor say?',
        'Mental Health' => 'What came out of speaking with them, and what support was offered?',
        'Personal' => 'What came out of speaking with them, and what support was offered?',
        'Bullying' => 'What did you find while looking into this?',
        'Harassment' => 'What did you find while looking into this?',
        'Administrative' => 'What was wrong, and what caused it?',
        'Facilities' => 'What did you find when it was checked?',
        'Equipment' => 'What did you find when it was checked?',
        'Physical' => 'What did you find when you looked into the incident?',
        'Safety' => 'What did you find when the hazard was assessed?',
        'Others' => 'What did you find while handling this?',
    ];

    /**
     * What to call this concern's handler notes.
     */
    public function handlingNoteLabel(): string
    {
        return self::HANDLING_NOTE_LABELS[$this->category] ?? 'Handling Notes';
    }

    /**
     * What to ask for in them.
     */
    public function handlingNoteHint(): string
    {
        return self::HANDLING_NOTE_HINTS[$this->category] ?? 'What did you find while handling this?';
    }

    public const CATEGORY_HINTS = [
        'Academic' => 'grades, subjects, schedules, teaching',
        'Mental Health' => 'stress, anxiety, how you are coping',
        'Personal' => 'family, money, housing',
        'Bullying' => 'threats, intimidation, humiliation',
        'Harassment' => 'unwanted conduct or discrimination',
        'Administrative' => 'this website itself — a page or button that will not work',
        'Facilities' => 'water, electricity, aircon, rooms, exits',
        'Equipment' => 'computers, lab equipment, chairs, internet',
        'Physical' => 'an accident or injury that already happened',
        'Safety' => 'a hazard that has not caused harm yet',
        'Others' => 'anything not listed above',
    ];

    /** The category with its clarifier, for the filing form's dropdown. */
    public static function categoryOptionLabel(?string $category): string
    {
        $label = self::categoryLabel($category);
        $hint = self::CATEGORY_HINTS[$category] ?? null;

        return $hint ? $label.' — '.$hint : $label;
    }

    /** What a student should see for a category. */
    public static function categoryLabel(?string $category): string
    {
        return self::CATEGORY_LABELS[$category] ?? (string) $category;
    }

    /** Convenience for views: {{ $concern->category_label }}. */
    public function getCategoryLabelAttribute(): string
    {
        return self::categoryLabel($this->category);
    }

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
    /**
     * The roles whose standing queue stops at their own college.
     *
     * Everything else here serves the whole institution: Guidance, General
     * Services, Legal Affairs, the VPAA, the Head of School. Those roles have
     * one holder, or one office, and narrowing them to a college would leave
     * cases with nobody above them.
     *
     * Mirrors ConcernController::COLLEGE_SCOPED_ROLES, which decides the same
     * question for the referral pickers: who may be HANDED a case, where this
     * decides who may READ one.
     */
    public const COLLEGE_BOUND_ROLES = [
        'Dean',
        'Program Chair',
        'Adviser',
        'Instructor',
        'Faculty/Staff',
    ];

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
        // The student asked for this NOT to go to their class adviser.
        'skip_adviser',
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
        'skip_adviser' => 'boolean',
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
     * When the desk now holding this concern received it.
     *
     * Derived rather than stored: there is no assigned_at column, and the
     * audit log already records every hand-off with the time it happened.
     * The most recent one is when the present holder got it; with no
     * hand-off at all, they have had it since it was filed.
     *
     * This is the question "how long has this been sitting with you" asks,
     * and it is not the same as the filing date once a case has moved -- a
     * concern filed a fortnight ago may have reached its current handler an
     * hour ago.
     */
    public function receivedAt()
    {
        $handover = $this->auditLogs
            ->where('action', 'status_updated')
            ->filter(fn (AuditLog $log) => str_starts_with((string) $log->description, 'Referred to'))
            ->sortByDesc('id')
            ->first();

        return $handover ? $handover->created_at : $this->created_at;
    }

    /**
     * The activity timeline, as this viewer is allowed to read it.
     *
     * Staff see the audit log in full: every hand-off, every name, every note
     * touched. That record is what makes the handling of a case answerable
     * afterwards, and nothing here trims it.
     *
     * The reporter sees the same case from outside. They are told what state
     * their concern is in and when it got there -- referred, being worked on,
     * resolved -- and never which desk it is sitting on. Who is holding a
     * report is the staff's business; a student who can watch it being passed
     * from the chair to the dean to Guidance can work out who read it.
     *
     * Returns entries, not logs: each is the log, the line to print, and
     * whether the actor's name may be shown beside it.
     *
     * @return \Illuminate\Support\Collection<int, array{log: AuditLog, label: string, showActor: bool}>
     */
    public function timelineFor(User $viewer)
    {
        if ($viewer->isEmployee()) {
            return $this->auditLogs->sortByDesc('id')->values()->map(fn (AuditLog $log) => [
                'log' => $log,
                'label' => self::fullLabel($log),
                'showActor' => true,
            ]);
        }

        $entries = collect();
        $previous = null;

        // Oldest first, so a run of hand-offs can be collapsed as it is read.
        foreach ($this->auditLogs->sortBy('id') as $log) {
            $milestone = self::reporterMilestone($log);

            if (! $milestone) {
                continue;
            }

            // Being referred onward, again and again, is one thing happening
            // to the student: their concern is somewhere else. The first of a
            // run is kept -- that is the moment it left the desk they were
            // told about -- and the rest add nothing. A later milestone ends
            // the run, so if the case comes back and is referred again, that
            // is a new line.
            if ($milestone['key'] === $previous) {
                continue;
            }

            $previous = $milestone['key'];

            $entries->push([
                'log' => $log,
                'label' => $milestone['label'],
                'showActor' => false,
            ]);
        }

        return $entries->reverse()->values();
    }

    /**
     * The staff reading of one entry: whatever was recorded, verbatim.
     *
     * Audit descriptions store raw status values (e.g. "in_progress"); the
     * underscores become spaces so they read as English.
     */
    private static function fullLabel(AuditLog $log): string
    {
        return $log->description
            ? str_replace('_', ' ', $log->description)
            : ucfirst(str_replace('_', ' ', $log->action));
    }

    /**
     * What one entry means to the reporter, or null if it means nothing.
     *
     * Triage and note-keeping are left out: the urgency a case was filed
     * under and the fact that somebody edited their own working notes are
     * internal, and a timeline of "Investigation notes updated" six times
     * tells a student only that something is happening somewhere.
     *
     * The key is what collapses a run, so every hand-off shares one.
     *
     * @return array{key: string, label: string}|null
     */
    private static function reporterMilestone(AuditLog $log): ?array
    {
        if ($log->action === 'concern_submitted') {
            return ['key' => 'submitted', 'label' => 'Concern submitted'];
        }

        if ($log->action === 'feedback_submitted') {
            return ['key' => 'feedback', 'label' => 'You rated how this was handled'];
        }

        // Their own identity being disclosed is the one staff action a
        // reporter is always entitled to see. The entry names the office and
        // the reason, never the individual, so it is shown as recorded.
        if ($log->action === 'identity_revealed') {
            return ['key' => 'identity', 'label' => (string) $log->description];
        }

        if ($log->action !== 'status_updated') {
            return null;
        }

        $description = (string) $log->description;

        // "Referred to Dean (Ms. Rosel O. Onesa (OIC), College of Computer
        // Studies)" becomes the fact without the address.
        if (str_starts_with($description, 'Referred to')) {
            return ['key' => 'referred', 'label' => 'Referred to another office'];
        }

        if ($description === 'Marked as resolved') {
            return ['key' => 'resolved', 'label' => 'Marked as resolved'];
        }

        // Closing a report without acting on it is the decision most likely
        // to be questioned, and the reason belongs to the person who filed
        // it. It is shown in full.
        if (str_starts_with($description, 'Closed without action')) {
            return ['key' => 'closed', 'label' => $description];
        }

        if (preg_match('/^Status changed from .+ to (.+)$/', $description, $matches)) {
            return [
                'key' => 'status:'.$matches[1],
                'label' => 'Status changed to '.str_replace('_', ' ', $matches[1]),
            ];
        }

        return ['key' => 'status', 'label' => 'Status updated'];
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
    /**
     * What this person may read, across every role they hold.
     *
     * Most people hold one. Some hold two -- an office staff member who also
     * covers Staff Admin, say -- and they should see what either role sees,
     * which is the union of the two rules rather than whichever happened to be
     * stored in users.role_id.
     *
     * The rules themselves are untouched: each role's existing rule is applied
     * to a sub-query and the results OR-ed. Rewriting them to "support" a
     * second role is how a permission model quietly loses a rule, and these
     * are the rules that keep a reported instructor out of the complaint about
     * them.
     */
    public function scopeVisibleTo($query, User $user)
    {
        $roles = $user->allRoleNames();

        if ($roles === []) {
            // No role: see nothing.
            return $query->whereRaw('1 = 0');
        }

        // A concern whose reporter has been deleted is nobody's to work on.
        // The row is kept so the deletion can be undone, but until the
        // account comes back the concern is out of every list -- including
        // the Head of School's. The relation respects the soft delete, so a
        // trashed owner simply fails this check.
        $query->whereHas('user');

        if (count($roles) === 1) {
            return self::applyRoleVisibility($query, $user, $roles[0]);
        }

        return $query->where(function ($outer) use ($roles, $user) {
            foreach ($roles as $role) {
                $outer->orWhere(function ($q) use ($role, $user) {
                    self::applyRoleVisibility($q, $user, $role);
                });
            }
        });
    }

    /**
     * One role's rule. See scopeVisibleTo() above for why it is separate.
     */
    private static function applyRoleVisibility($query, User $user, ?string $role)
    {
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

        // Legal Affairs. Referral-gated, exactly like GAD: no category of its
        // own, because a student does not file "a legal matter" -- a handler
        // decides a case needs legal counsel and sends it here.
        if ($role === 'Legal Affairs') {
            return $query->where(function ($q) use ($user, $involved) {
                $q->where(function ($sub) {
                    $sub->where('referred_to', 'Legal Affairs')
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
                // No standing category any more. This office read every
                // Administrative concern while that category meant enrolment,
                // records and fees -- the work it actually does. The category
                // now means "this website is broken", which belongs to the
                // people who run the website, so the window moved with it.
                $q->where(function ($sub) {
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

        // One standing category, and only one: reports that this website is
        // broken, which routeConcern() sends here. An office that cannot read
        // what is assigned to it is worse than one nobody was assigned,
        // because the queue looks handled.
        //
        // Everything else stays out of reach. Managing accounts and roles
        // needs no view of students' complaints, so mental health, harassment
        // and the rest arrive only by a deliberate referral.
        if ($role === 'System Admin') {
            return $query->where(function ($q) use ($user, $involved) {
                $q->where('category', 'Administrative')   // a fault in the system
                  ->orWhere(function ($sub) {
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
                  ->orWhere(function ($sub) use ($role, $user) {
                      $sub->where('referred_to', $role)
                          ->whereNotIn('status', self::TERMINAL_STATUSES);

                      // "Referred to Instructor" used to match on the word
                      // alone, so a hand-off inside one college was readable
                      // by every instructor in the institution. The VPAA is
                      // left institution-wide on purpose: there is one of
                      // her, and escalation is the whole of her queue.
                      self::narrowToTheirCollege($sub, $user, $role);
                  })
                  ->orWhere($involved);
            });
        }

        if (in_array($role, ['Adviser', 'Program Chair', 'Dean'], true)) {
            return $query->where(function ($q) use ($user, $role, $involved) {
                $q->where('assigned_to', $user->id)
                  ->orWhere(function ($sub) use ($role, $user) {
                      $sub->where('referred_to', $role)
                          ->whereNotIn('status', self::TERMINAL_STATUSES);

                      self::narrowToTheirCollege($sub, $user, $role);
                  })
                  ->orWhere(function ($sub) use ($user, $role) {
                      $sub->where('status', 'submitted')
                          ->whereIn('category', self::TEACHING_CATEGORIES);

                      // The standing academic queue, which had no college in
                      // it. Every Adviser, Chair and Dean in the institution
                      // could read every submitted teaching concern filed
                      // anywhere -- a Health Sciences dean opening a Computer
                      // Studies case is how it was noticed.
                      self::narrowToTheirCollege($sub, $user, $role);
                  })
                  // Anything they have personally handled (history / reference).
                  ->orWhere($involved);
            });
        }

        // Unknown role: see nothing.
        return $query->whereRaw('1 = 0');
    }

    /**
     * Narrow a standing window to the part of the college this person serves.
     *
     * Applies to the windows a role gets by virtue of being that role. It is
     * deliberately NOT applied to "assigned to me" or "I have handled this":
     * those are facts about the person, and a concern handed to somebody
     * stays readable by them wherever it came from.
     *
     * A Program Chair is narrowed twice over, to their college and then to
     * the one programme they chair -- the same rule the referral picker uses,
     * where the chair of Information Systems is not an alternative for an
     * Information Technology student.
     *
     * Where the data cannot place somebody -- no college recorded on the
     * person, or none on the concern -- the window is left as it was. Closing
     * it on missing data would make concerns unreachable rather than private,
     * and an unreadable concern in a queue is worse than a wide one: it looks
     * handled.
     */
    private static function narrowToTheirCollege($query, User $user, string $role)
    {
        // The guard lives here rather than at each call site, so a role that
        // is not bound to a college cannot be narrowed by accident. The VPAA
        // is the one this protects: she has a department -- Academic Affairs
        // -- which is an office and not a college, so narrowing on it
        // silently emptied her queue of every escalation in the institution.
        if (! in_array($role, self::COLLEGE_BOUND_ROLES, true) || ! $user->department) {
            return $query;
        }

        $query->where(function ($q) use ($user) {
            $q->whereNull('department')
              ->orWhere('department', $user->department);
        });

        if ($role === 'Program Chair' && $user->course) {
            $query->where(function ($q) use ($user) {
                $q->whereNull('course')
                  ->orWhere('course', $user->course);
            });
        }

        return $query;
    }
}