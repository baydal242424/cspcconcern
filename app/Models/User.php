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
 * pending accounts wait for an Admin (AdminController). google_id gets
 * linked on the first successful CSPC Mail sign-in, and
 * approved_by/approved_at record who decided and when.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property int|null $role_id
 * @property string|null $department
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
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
#[Fillable(['name', 'email', 'password', 'role_id', 'department', 'google_id', 'status', 'approved_by', 'approved_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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
            'password' => 'hashed',
        ];
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
}

