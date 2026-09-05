<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * An account, student or staff. The role decides all permissions (see
 * Concern::scopeVisibleTo). status must be 'approved' to sign in --
 * 'banned' accounts are logged out on their very next request (see
 * UpdateLastSeen middleware), not just blocked at the next login.
 * google_id gets linked on the first successful CSPC Mail sign-in, and
 * approved_by/approved_at record who decided and when; banned_by/banned_at/
 * ban_reason record the same for a ban.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property int|null $role_id
 * @property string|null $department
 * @property string|null $student_id
 * @property string|null $course
 * @property \Illuminate\Support\Carbon|null $last_seen_at
 * @property \Illuminate\Support\Carbon|null $banned_at
 * @property int|null $banned_by
 * @property string|null $ban_reason
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @property-read Role|null $role
 * @property-read \Illuminate\Database\Eloquent\Collection|Concern[] $submittedConcerns
 * @property-read \Illuminate\Database\Eloquent\Collection|Concern[] $assignedConcerns
 * @property-read \Illuminate\Database\Eloquent\Collection|Referral[] $referralsSent
 * @property-read \Illuminate\Database\Eloquent\Collection|Referral[] $referralsReceived
 * @property-read \Illuminate\Database\Eloquent\Collection|Notification[] $notifications
 * @property-read \Illuminate\Database\Eloquent\Collection|AuditLog[] $auditLogs
 * @property-read bool $is_online
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
#[Fillable(['name', 'email', 'password', 'role_id', 'department', 'student_id', 'course',
    // The student's class section, e.g. 3A. Staff leave it null.
    'section', 'google_id', 'status', 'requested_role_id', 'role_requested_at', 'approved_by', 'approved_at', 'last_seen_at', 'banned_by', 'banned_at', 'ban_reason', 'email_verified_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** How recently last_seen_at must have ticked for the account to count as "online". */
    private const ONLINE_THRESHOLD_MINUTES = 5;

    /**
     * The undergraduate programs CSPC Nabua offers, keyed by the college that
     * runs them. Registration asks for the college first and then narrows the
     * course list to that college, so a student cannot pair "BS Nursing" with
     * the College of Computer Studies. College names match the ones used for
     * concern routing in ConcernController.
     *
     * @var array<string, list<string>>
     */
    public const COURSES_BY_COLLEGE = [
        'College of Computer Studies' => [
            'BS Information Technology',
            'BS Computer Science',
            'BS Information Systems',
            'Bachelor of Library and Information Science',
        ],
        'College of Engineering and Architecture' => [
            'BS Civil Engineering',
            'BS Electrical Engineering',
            'BS Electronics Engineering',
            'BS Mechanical Engineering',
            'BS Computer Engineering',
            'BS Architecture',
        ],
        'College of Tourism, Hospitality and Business Management' => [
            'BS Hospitality Management',
            'BS Tourism Management',
            'BS Office Administration',
            'BS Entrepreneurship',
            'BS Business Administration major in Financial Management',
        ],
        'College of Health Sciences' => [
            'BS Nursing',
            'BS Midwifery',
        ],
        'College of Technological and Development Education' => [
            'Bachelor of Physical Education',
            'Bachelor of Culture and Arts Education',
            'Bachelor of Special Needs Education',
            'Bachelor of Technical-Vocational Teacher Education',
        ],
        'College of Arts and Sciences' => [
            'BA English Language Studies',
            'BS Development Communication',
            'BS Public Administration',
            'BS Mathematics',
            'BS Applied Mathematics',
            'Bachelor in Human Services',
        ],
    ];

    /**
     * Every course name, flattened out of COURSES_BY_COLLEGE.
     *
     * @return list<string>
     */
    public static function allCourses(): array
    {
        return array_merge(...array_values(self::COURSES_BY_COLLEGE));
    }

    /**
     * Every role that is NOT a student. Kept as one list so "is this person
     * an employee?" is answered in a single place instead of being spelled
     * out at each call site, where a newly added role would be silently
     * missed.
     *
     * @var list<string>
     */
    public const EMPLOYEE_ROLES = [
        'Vice President for Academic Affairs',
        'Adviser',
        'Instructor',
        'Faculty/Staff',
        'Program Chair',
        'Dean',
        'Guidance Counselor',
        'System Admin',
        'Staff Admin',
        'Head of School',
        'Gender and Development',
        'General Services',
    ];

    /**
     * Scope to student accounts.
     *
     * Deliberately driven by ROLE, not by the email domain. The domain only
     * decides what a brand-new account starts as (see
     * AuthController::DOMAIN_ROLES); role_id is what every permission check
     * in Concern::scopeVisibleTo() actually reads, and an Admin can change it
     * afterwards. Filtering on the domain here would give a second, silently
     * disagreeing answer for anyone who has been promoted.
     */
    public function scopeStudents($query)
    {
        return $query->whereHas('role', fn ($q) => $q->where('name', 'Student'));
    }

    /**
     * Scope to employee accounts -- faculty, deans, counselors, admins and
     * the Head of School.
     */
    public function scopeEmployees($query)
    {
        return $query->whereHas('role', fn ($q) => $q->whereIn('name', self::EMPLOYEE_ROLES));
    }

    /**
     * Whether this account holds any staff-type role.
     */
    public function isEmployee(): bool
    {
        return in_array(optional($this->role)->name, self::EMPLOYEE_ROLES, true);
    }

    /**
     * Whether this account's CSPC address has been confirmed. Signing in
     * through Google proves the person controls that mailbox, so it is
     * stamped there; there is no separate verification step.
     */
    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    /**
     * Whether this Student still has to tell us which college and course they
     * belong to. True for every account auto-provisioned by CSPC Mail
     * sign-in, which is now the only way a student account is created. A
     * concern's department is taken from the reporter's account, so this must
     * be filled in before they can file one.
     */
    public function needsProfileCompletion(): bool
    {
        if (optional($this->role)->name !== 'Student') {
            return false;
        }

        // Section counts as missing detail now, not an optional extra. It was
        // left optional because nothing routed on it -- that stopped being
        // true when Academic, Physical, Safety and Others started reaching a
        // student's own class adviser, who is attached to a SECTION and not to
        // a college. Without it the concern falls back to college-level
        // routing, and the filing form cannot offer the adviser as the subject
        // of the complaint at all, because it does not know who they are.
        //
        // Students already on file without one are asked once, on their next
        // sign-in, rather than being left in a state the form cannot serve.
        return ! array_key_exists((string) $this->department, self::COURSES_BY_COLLEGE)
            || blank($this->course)
            || blank($this->section);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'approved_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'banned_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Whether this account has pinged the server within the online threshold.
     */
    public function getIsOnlineAttribute(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subMinutes(self::ONLINE_THRESHOLD_MINUTES));
    }

    /**
     * The roles a staff member may ask for on first sign-in.
     *
     * Every one of these still needs an administrator to grant it -- asking is
     * not becoming. The two admin roles are absent on purpose: those are the
     * keys to the system, and nobody hands themselves the keys, not even as a
     * request sitting in a queue where a tired admin might wave it through.
     * Head of School and the VPAA are absent for the same reason.
     *
     * @var list<string>
     */
    public const REQUESTABLE_ROLES = [
        'Instructor',
        'Program Chair',
        'Dean',
        'Guidance Counselor',
        'Gender and Development',
        'General Services',
        'Faculty/Staff',
    ];

    /** The role this person has asked an administrator to grant them. */
    public function requestedRole()
    {
        return $this->belongsTo(Role::class, 'requested_role_id');
    }

    /**
     * Whether a staff member still has to say where they work.
     *
     * Students have their own version of this (needsProfileCompletion). This
     * is the employee side: an account auto-provisioned by a cspc.edu.ph
     * sign-in knows only the address, so it has no college, no programme, and
     * the lowest staff role until the person fills it in.
     *
     * Answered once. Somebody who has given a department is done, whether or
     * not their role request has been granted yet -- the wait is the admin's
     * to clear, and blocking the app on it would lock a real instructor out of
     * a system they are supposed to be handling concerns in.
     */
    public function needsStaffProfile(): bool
    {
        return $this->isEmployee() && blank($this->department);
    }

    /**
     * How many year levels a programme runs for.
     *
     * Only used by the year-level promotion: it decides who moves up and who
     * is already in their final year. A student in their last year is NOT
     * promoted -- they are graduating, and "graduated" is not a section.
     * Pushing them to a fifth year would invent a section nobody advises, and
     * their concerns would fall out of adviser routing to the college
     * fallback without anybody noticing.
     *
     * Four years unless listed here. Architecture is the five-year one.
     */
    public const YEARS_BY_COURSE = [
        'BS Architecture' => 5,
    ];

    public static function finalYearFor(?string $course): int
    {
        return self::YEARS_BY_COURSE[$course] ?? 4;
    }

    /**
     * The programmes an instructor teaches, as rows of instructor_programmes.
     *
     * Separate from users.course, which holds ONE programme meaning "the
     * programme this person belongs to" -- a student's own, or the single
     * programme a Program Chair chairs. An instructor teaches several, so it
     * could not live in that column.
     *
     * Empty is normal: an instructor who has not filled it in yet, or one who
     * teaches across programmes. They stay grouped under their college.
     */
    public function programmesTaught()
    {
        return $this->hasMany(InstructorProgramme::class);
    }

    /**
     * Replace this instructor's programme list.
     *
     * Silently drops anything the user's own college does not offer, so a
     * hand-posted form cannot file somebody under a programme they could not
     * possibly teach.
     *
     * @param  array<int, string>  $courses
     */
    public function syncProgrammesTaught(array $courses): void
    {
        $offered = self::COURSES_BY_COLLEGE[$this->department] ?? [];
        $courses = array_values(array_unique(array_intersect($courses, $offered)));

        $this->programmesTaught()->whereNotIn('course', $courses)->delete();

        foreach ($courses as $course) {
            $this->programmesTaught()->firstOrCreate(['course' => $course]);
        }

        $this->unsetRelation('programmesTaught');
    }

    /**
     * Get the role that the user belongs to.
     */
    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Get the concerns submitted by this user.
     */
    public function submittedConcerns()
    {
        return $this->hasMany(Concern::class, 'user_id');
    }

    /**
     * Get the concerns assigned to this user.
     */
    public function assignedConcerns()
    {
        return $this->hasMany(Concern::class, 'assigned_to');
    }

    /**
     * Get the referrals sent by this user.
     */
    public function referralsSent()
    {
        return $this->hasMany(Referral::class, 'referred_by');
    }

    /**
     * Get the referrals received by this user.
     */
    public function referralsReceived()
    {
        return $this->hasMany(Referral::class, 'referred_to');
    }

    /**
     * Get the notifications for this user.
     */
    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    /**
     * Get the audit logs for this user.
     */
    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class);
    }

    /**
     * Get the admin who approved or rejected this account.
     */
    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the admin who banned this account.
     */
    public function bannedBy()
    {
        return $this->belongsTo(User::class, 'banned_by');
    }
}

