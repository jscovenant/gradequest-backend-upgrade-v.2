<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use App\Traits\HasSubscriptionUsageGuard;


class User extends Authenticatable
{
    use HasRoles;
    use HasApiTokens;
   use HasSubscriptionUsageGuard;
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */


protected $guarded = [];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */


protected $casts = [
    'email_verified_at' => 'datetime',
    'password' => 'hashed',
    'password_reset_expires_at' => 'datetime',
    'default_password' => 'encrypted', 
    'force_password_change' => 'boolean',
    'password_changed_at' => 'datetime',
    'twilio_auth_token' => 'encrypted',
    'student_status_changed_at' => 'datetime',
    'teacher_status_changed_at' => 'datetime',
    'phone_validated_at' => 'datetime',
    'whatsapp_verified_at' => 'datetime',
    'whatsapp_verification_expires_at' => 'datetime',
    'super_admin_permissions' => 'array',
    'last_login_at' => 'datetime',
];

public const SUPER_ADMIN_PERMISSION_MAP = [
    'owner' => ['dashboard', 'billing', 'finance', 'support', 'sales', 'marketing', 'content', 'settings', 'audit', 'staff'],
    'operations' => ['dashboard', 'support', 'billing', 'audit'],
    'finance' => ['dashboard', 'billing', 'finance', 'audit'],
    'support' => ['dashboard', 'support', 'audit'],
    'sales_manager' => ['dashboard', 'sales', 'marketing', 'audit'],
];


public function scopeForSchool(Builder $query, ?int $schoolId): Builder
{
    if (! $schoolId) {
        return $query;
    }

    return $query->where($query->getModel()->getTable() . '.school_id', $schoolId);
}

public function scopeWithRole(Builder $query, string $role): Builder
{
    return $query->whereRaw('LOWER(role) = ?', [strtolower($role)]);
}

public function isSuperAdminUser(): bool
{
    $role = strtolower(str_replace([' ', '-', '_'], '', (string) $this->role));

    return in_array($role, ['superadmin', 'platformstaff'], true);
}

public function superAdminTypeLabel(): string
{
    return match ($this->super_admin_type ?: 'owner') {
        'operations' => 'Operations Admin',
        'finance' => 'Finance Admin',
        'support' => 'Support Admin',
        'sales_manager' => 'Sales Manager',
        default => 'Super Admin Owner',
    };
}

public function superAdminPermissions(): array
{
    if (! $this->isSuperAdminUser()) {
        return [];
    }

    $type = $this->super_admin_type ?: 'owner';
    $defaults = self::SUPER_ADMIN_PERMISSION_MAP[$type] ?? self::SUPER_ADMIN_PERMISSION_MAP['owner'];
    $custom = is_array($this->super_admin_permissions) ? $this->super_admin_permissions : [];

    return array_values(array_unique(array_filter(array_merge($defaults, $custom))));
}

public function hasSuperAdminPermission(string $permission): bool
{
    if (! $this->isSuperAdminUser()) {
        return false;
    }

    if (strtolower(str_replace([' ', '-', '_'], '', (string) $this->role)) === 'superadmin') {
        return true;
    }

    if ($permission === ($this->super_admin_type ?: 'owner')) {
        return true;
    }

    return in_array('all', $this->superAdminPermissions(), true)
        || in_array($permission, $this->superAdminPermissions(), true);
}


public function schoolSubscriptions()
{
    return $this->hasMany(\App\Models\Subscription::class, 'user_id');
}





public function records()
{
    return $this->hasMany(FinancialRecord::class, 'school_id', 'id');
}


public function children()
{
    return $this->belongsToMany(User::class, 'parent_students', 'parent_id', 'student_id')
                ->withTimestamps();
}

public function parents()
{
    return $this->belongsToMany(User::class, 'parent_student', 'student_id', 'parent_id')
                ->withTimestamps();
}

public function class()
{
    return $this->belongsTo(StudentClass::class, 'class_id');
}



    public function levelEnrollment()
    {
        return $this->hasOne(TeacherEnrollment::class, 'user_id', 'id');
    }
    

    public function level()
    {
          return $this->belongsTo(StudentClass::class, 'level_id', 'id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id', 'id');
    }

    public function subjectenroll()
    {
        return $this->hasMany(SubjectEnroll::class);
    }



    public function firsttermresults()
    {
        return $this->hasMany(FirstTermResult::class);
    }

    public function secondtermresults()
    {
        return $this->hasMany(SecondTermResult::class);
    }

    public function thirdtermresults()
    {
        return $this->hasMany(ThirdTermResult::class);
    }

    public function resultaverage()
    {
        return $this->hasOne(Average::class);
    }

    public function section()
    {
        return $this->belongsTo(Section::class, 'section_id', 'id');
    }

    public function hasRole($role)
    {
        return $this->role === $role;
    }

    public function getIsTeacherAttribute()
    {
        return $this->hasRole('Teacher');
    }

    public function getIsAdminAttribute()
    {
        return $this->hasRole('Admin');
    }

    public function getIsStudentAttribute()
    {
        return $this->hasRole('Student');
    }
    
public function parentAccount()
{
    return $this->belongsToMany(User::class, 'parent_students', 'student_id', 'parent_id')->first();
}



public function schoolsetting()
{
    return $this->hasOne(SchoolSetting::class, 'user_id', 'id');
}



    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function teacherEnrollment()
{
    return $this->hasOne(TeacherEnrollment::class, 'user_id');
}

public function school()
{
    return $this->belongsTo(SchoolSetting::class);
}

public function attendances()
{
    return $this->hasMany(Attendance::class, 'student_id');
}

public function biometricId()
{
    return $this->hasOne(BiometricId::class);
}


public function activeSubscription()
{
    return $this->hasOne(Subscription::class)->where('status', 'active');
}

public function subscriptions()
{
    return $this->hasMany(\App\Models\Subscription::class, 'user_id');
}


public function studentFees()
{
    return $this->hasMany(StudentFee::class, 'student_id');
}




public function hasFeature(string $featureKey): ?array
{
    // Always get the school ADMIN (owner of subscription)
    $schoolAdmin = User::where('school_id', $this->school_id)
        ->where('role', 'Admin')
        ->first();

    if (!$schoolAdmin) {
        return null;
    }

    // Get admin's active subscription with plan
    $subscription = $schoolAdmin->activeSubscription()->with('plan')->first();

    if (!$subscription || !$subscription->plan) {
        return null;
    }

    // Decode plan features
    $features = collect(
        is_string($subscription->plan->features)
            ? json_decode($subscription->plan->features, true)
            : $subscription->plan->features
    );

    // Return feature if it exists
    return $features->firstWhere('feature_key', $featureKey);
}




 public function routeNotificationForWhatsapp(): ?string
    {
        return $this->phone ?: null;
    }








}
