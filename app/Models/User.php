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
use Illuminate\Support\Facades\DB;


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

    protected $appends = ['name'];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'default_password',
        'remember_token',
        'twilio_auth_token',
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

public const ROLE_SUPER_ADMIN = 'super_admin';
public const ROLE_PLATFORM_STAFF = 'platform_staff';
public const ROLE_PROPRIETOR = 'proprietor';
public const ROLE_PRINCIPAL = 'principal';
public const ROLE_OPERATOR = 'operator';
public const ROLE_BURSAR = 'bursar';
public const ROLE_CLASS_TEACHER = 'class_teacher';
public const ROLE_SUBJECT_TEACHER = 'subject_teacher';
public const ROLE_TEACHER = 'teacher';
public const ROLE_PARENT = 'parent';
public const ROLE_STUDENT = 'student';

public const SUPER_ADMIN_PERMISSION_MAP = [
    'owner' => ['dashboard', 'billing', 'finance', 'support', 'sales', 'marketing', 'content', 'settings', 'audit', 'staff'],
    'operations' => ['dashboard', 'support', 'billing', 'audit'],
    'finance' => ['dashboard', 'billing', 'finance', 'audit'],
    'support' => ['dashboard', 'support', 'audit'],
    'sales_manager' => ['dashboard', 'sales', 'marketing', 'audit'],
];

public static function normalizeRole(?string $role): string
{
    $r = strtolower(str_replace([' ', '-'], '_', trim((string) $role)));
    return match ($r) {
        'superadmin', 'super_admin' => 'super_admin',
        'platformstaff', 'platform_staff' => 'platform_staff',
        'admin', 'owner', 'proprietor', 'school_owner' => 'proprietor',
        'principal', 'head_teacher', 'headmaster', 'headmistress' => 'principal',
        'operator', 'registrar', 'secretary', 'admin_officer' => 'operator',
        'bursar', 'accountant' => 'bursar',
        'class_teacher', 'form_master', 'form_teacher' => 'class_teacher',
        'subject_teacher' => 'subject_teacher',
        'teacher' => 'teacher',
        'parent', 'guardian' => 'parent',
        'student', 'pupil' => 'student',
        'sales_representative', 'sales_rep', 'salesrep' => 'sales_representative',
        default => $r,
    };
}

public function scopeForSchool(Builder $query, ?int $schoolId): Builder
{
    if (! $schoolId) {
        return $query;
    }

    return $query->where($query->getModel()->getTable() . '.school_id', $schoolId);
}

public function scopeWithRole(Builder $query, string $role): Builder
{
    $norm = self::normalizeRole($role);
    if ($norm === 'proprietor') {
        return $query->whereIn(\Illuminate\Support\Facades\DB::raw('LOWER(role)'), ['admin', 'owner', 'proprietor', 'school_owner']);
    }
    if ($norm === 'teacher') {
        return $query->whereIn(\Illuminate\Support\Facades\DB::raw('LOWER(role)'), ['teacher', 'class_teacher', 'subject_teacher']);
    }
    if ($norm === 'super_admin') {
        return $query->whereIn(\Illuminate\Support\Facades\DB::raw('LOWER(role)'), ['super-admin', 'super_admin', 'superadmin']);
    }

    return $query->whereRaw('LOWER(role) = ?', [strtolower($role)]);
}

public function isSuperAdminUser(): bool
{
    $norm = self::normalizeRole($this->role);
    return in_array($norm, ['super_admin', 'platform_staff'], true);
}

public function isProprietor(): bool
{
    $norm = self::normalizeRole($this->role);
    return $norm === 'proprietor';
}

public function isPrincipal(): bool
{
    $norm = self::normalizeRole($this->role);
    return $norm === 'principal';
}

public function isOperator(): bool
{
    $norm = self::normalizeRole($this->role);
    return $norm === 'operator';
}

public function isBursar(): bool
{
    $norm = self::normalizeRole($this->role);
    return $norm === 'bursar';
}

public function isTeacher(): bool
{
    $norm = self::normalizeRole($this->role);
    return in_array($norm, ['teacher', 'class_teacher', 'subject_teacher'], true);
}

public function isClassTeacher(): bool
{
    $norm = self::normalizeRole($this->role);
    if ($norm === 'class_teacher') {
        return true;
    }
    if ($norm === 'teacher') {
        return $this->levelEnrollment()->exists();
    }
    return false;
}

public function isSubjectTeacher(): bool
{
    $norm = self::normalizeRole($this->role);
    if ($norm === 'subject_teacher') {
        return true;
    }
    if ($norm === 'teacher') {
        return $this->subjectenroll()->exists() || ! $this->levelEnrollment()->exists();
    }
    return false;
}

public function isParent(): bool
{
    $norm = self::normalizeRole($this->role);
    return $norm === 'parent';
}

public function isStudent(): bool
{
    $norm = self::normalizeRole($this->role);
    return $norm === 'student';
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
        if (empty($role)) {
            return false;
        }

        if (is_array($role)) {
            return collect($role)->contains(fn ($r) => $this->hasRole($r));
        }

        if (strcasecmp((string) $this->role, (string) $role) === 0) {
            return true;
        }

        $myNorm = self::normalizeRole($this->role);
        $targetNorm = self::normalizeRole($role);

        if ($myNorm === $targetNorm) {
            return true;
        }

        // 'Admin' / 'proprietor' equivalence
        if (in_array($targetNorm, ['admin', 'proprietor'], true) && in_array($myNorm, ['admin', 'proprietor'], true)) {
            return true;
        }

        // 'Teacher' matches 'class_teacher', 'subject_teacher', 'teacher'
        if (in_array($targetNorm, ['teacher', 'class_teacher', 'subject_teacher'], true) && in_array($myNorm, ['teacher', 'class_teacher', 'subject_teacher'], true)) {
            return true;
        }

        // 'Super-Admin' matches 'super_admin'
        if (in_array($targetNorm, ['super_admin', 'superadmin'], true) && in_array($myNorm, ['super_admin', 'superadmin'], true)) {
            return true;
        }

        try {
            return $this->roles()->whereRaw('LOWER(name) = ?', [strtolower((string) $role)])->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    public function getNameAttribute($value)
    {
        if (! empty($value)) {
            return $value;
        }

        $composed = trim(implode(' ', array_filter([
            $this->attributes['firstname'] ?? null,
            $this->attributes['surname'] ?? null,
            $this->attributes['third_name'] ?? null,
        ])));

        if (! empty($composed)) {
            return $composed;
        }

        return $this->attributes['username'] ?? ('User #' . ($this->attributes['id'] ?? ''));
    }

    public function getIsTeacherAttribute()
    {
        return $this->hasRole('Teacher') || $this->isClassTeacher() || $this->isSubjectTeacher();
    }

    public function getIsAdminAttribute()
    {
        return $this->hasRole('Admin') || $this->isProprietor();
    }

    public function getIsStudentAttribute()
    {
        return $this->hasRole('Student') || $this->isStudent();
    }
    
public function parentAccount()
{
    return $this->belongsToMany(User::class, 'parent_students', 'student_id', 'parent_id')->first();
}

public function studentResultsV2()
{
    return $this->hasMany(StudentResultV2::class, 'user_id');
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




    public function getDefaultPasswordAttribute($value): ?string
    {
        if (!$value) {
            return null;
        }

        try {
            return \Illuminate\Support\Facades\Crypt::decryptString($value);
        } catch (\Throwable $e) {
            if (is_string($value) && strlen($value) < 60 && !str_starts_with($value, 'eyJ')) {
                return $value;
            }
            return null;
        }
    }

    public function setDefaultPasswordAttribute($value): void
    {
        if (!$value) {
            $this->attributes['default_password'] = null;
            return;
        }

        try {
            $this->attributes['default_password'] = \Illuminate\Support\Facades\Crypt::encryptString($value);
        } catch (\Throwable $e) {
            $this->attributes['default_password'] = $value;
        }
    }

    public function readableDefaultPassword(): ?string
    {
        return $this->default_password;
    }
}
