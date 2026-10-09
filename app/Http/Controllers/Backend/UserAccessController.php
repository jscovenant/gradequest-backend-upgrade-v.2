<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\SchoolSetting;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class UserAccessController extends Controller
{
    public const AVAILABLE_PERMISSIONS = [
        [
            'category' => 'Core Platform Access',
            'permissions' => [
                [
                    'key' => 'dashboard',
                    'label' => 'Command Center & Analytics',
                    'description' => 'View platform command center, gross revenue metrics, and system operations overview.',
                    'icon' => 'bi-speedometer2',
                    'color' => '#2563EB',
                ],
                [
                    'key' => 'schools',
                    'label' => 'Schools & Tenant Directory',
                    'description' => 'Manage registered institutions, onboard new tenants, and configure school profiles.',
                    'icon' => 'bi-buildings',
                    'color' => '#0F2744',
                ],
                [
                    'key' => 'staff',
                    'label' => 'User Roles & Permissions',
                    'description' => 'Create administrative accounts, assign roles, and toggle granular user permissions.',
                    'icon' => 'bi-person-gear',
                    'color' => '#7C3AED',
                ],
            ],
        ],
        [
            'category' => 'Financial & Revenue Management',
            'permissions' => [
                [
                    'key' => 'finance',
                    'label' => 'Financial Reports & Royalty Splits',
                    'description' => 'Access real-time platform revenue analytics, tuition GMV, and payment reconciliation.',
                    'icon' => 'bi-cash-coin',
                    'color' => '#059669',
                ],
                [
                    'key' => 'billing',
                    'label' => 'Billing Policy & Subscription Plans',
                    'description' => 'Configure platform pricing edition tiers, grant billing waivers, and review invoices.',
                    'icon' => 'bi-credit-card-2-front',
                    'color' => '#D97706',
                ],
                [
                    'key' => 'sales',
                    'label' => 'Sales Leads & Commission Payouts',
                    'description' => 'Track sales representative conversion pipelines and authorize commission disbursements.',
                    'icon' => 'bi-graph-up-arrow',
                    'color' => '#2563EB',
                ],
            ],
        ],
        [
            'category' => 'Platform Operations & Support',
            'permissions' => [
                [
                    'key' => 'support',
                    'label' => 'Helpdesk & School Diagnostics',
                    'description' => 'Respond to school support requests, view system logs, and inspect tenant issues.',
                    'icon' => 'bi-headset',
                    'color' => '#0284C7',
                ],
                [
                    'key' => 'cbt_management',
                    'label' => 'CBT Exams Control & Test Banks',
                    'description' => 'Oversee Computer-Based Testing configurations, question banks, and offline runner.',
                    'icon' => 'bi-cpu',
                    'color' => '#9333EA',
                ],
                [
                    'key' => 'domains',
                    'label' => 'Custom Domain DNS & Provisioning',
                    'description' => 'Approve school domain requests, manage DNS registrar orders, and review SSL status.',
                    'icon' => 'bi-globe2',
                    'color' => '#059669',
                ],
                [
                    'key' => 'twilio_whatsapp',
                    'label' => 'Twilio & WhatsApp Messaging',
                    'description' => 'Configure Twilio WhatsApp sender registration, test dispatches, and webhook health.',
                    'icon' => 'bi-whatsapp',
                    'color' => '#16A34A',
                ],
            ],
        ],
        [
            'category' => 'Content, Marketing & Governance',
            'permissions' => [
                [
                    'key' => 'marketing',
                    'label' => 'Marketing & Mass Messaging',
                    'description' => 'Broadcast promotional emails, manage newsletter subscribers, and distribution lists.',
                    'icon' => 'bi-envelope-paper',
                    'color' => '#EA580C',
                ],
                [
                    'key' => 'content',
                    'label' => 'Blog & Platform Newsroom',
                    'description' => 'Draft, review, and publish platform announcements, articles, and documentation.',
                    'icon' => 'bi-newspaper',
                    'color' => '#475569',
                ],
                [
                    'key' => 'settings',
                    'label' => 'Maintenance Mode & Platform Config',
                    'description' => 'Toggle platform maintenance mode, set alert messages, and configure core variables.',
                    'icon' => 'bi-sliders',
                    'color' => '#DC2626',
                ],
                [
                    'key' => 'audit',
                    'label' => 'Security Audit & Activity Logs',
                    'description' => 'Inspect comprehensive administrator activity audit trails and security event logs.',
                    'icon' => 'bi-shield-check',
                    'color' => '#0F2744',
                ],
            ],
        ],
    ];

    public const AVAILABLE_ROLES = [
        ['key' => 'Super-Admin', 'label' => 'Super Admin (Owner)', 'category' => 'Platform', 'color' => '#0F2744'],
        ['key' => 'Platform-Staff', 'label' => 'Platform Staff', 'category' => 'Platform', 'color' => '#4338CA'],
        ['key' => 'proprietor', 'label' => 'Proprietor / School Owner', 'category' => 'School Admin', 'color' => '#1D4ED8'],
        ['key' => 'principal', 'label' => 'Principal / Head Teacher', 'category' => 'School Admin', 'color' => '#0F766E'],
        ['key' => 'operator', 'label' => 'School Operator', 'category' => 'School Admin', 'color' => '#92400E'],
        ['key' => 'bursar', 'label' => 'Bursar / Accountant', 'category' => 'School Admin', 'color' => '#B45309'],
        ['key' => 'teacher', 'label' => 'Teacher', 'category' => 'Academic Staff', 'color' => '#059669'],
        ['key' => 'class_teacher', 'label' => 'Class / Form Teacher', 'category' => 'Academic Staff', 'color' => '#047857'],
        ['key' => 'subject_teacher', 'label' => 'Subject Teacher', 'category' => 'Academic Staff', 'color' => '#065F46'],
        ['key' => 'Parent', 'label' => 'Parent / Guardian', 'category' => 'Client', 'color' => '#6D28D9'],
        ['key' => 'Student', 'label' => 'Student', 'category' => 'Client', 'color' => '#64748B'],
        ['key' => 'Sales-Representative', 'label' => 'Sales Representative', 'category' => 'Growth', 'color' => '#2563EB'],
    ];

    /**
     * Get users listing with role, status, permissions and filters.
     */
    public function index(Request $request)
    {
        $query = User::query()
            ->leftJoin('school_settings', 'users.school_id', '=', 'school_settings.id')
            ->select('users.*', 'school_settings.school_name');

        // Search
        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('users.firstname', 'like', "%{$search}%")
                  ->orWhere('users.surname', 'like', "%{$search}%")
                  ->orWhere('users.email', 'like', "%{$search}%")
                  ->orWhere('users.phone', 'like', "%{$search}%")
                  ->orWhere('users.reg_no', 'like', "%{$search}%")
                  ->orWhere('school_settings.school_name', 'like', "%{$search}%");
            });
        }

        // Role filter
        if ($role = trim((string) $request->input('role'))) {
            if (in_array(strtolower($role), ['superadmin', 'super-admin', 'super_admin'], true)) {
                $query->whereIn(DB::raw('LOWER(users.role)'), ['super-admin', 'super_admin', 'superadmin']);
            } elseif (in_array(strtolower($role), ['platformstaff', 'platform-staff', 'platform_staff'], true)) {
                $query->whereIn(DB::raw('LOWER(users.role)'), ['platform-staff', 'platform_staff', 'platformstaff']);
            } elseif (strtolower($role) === 'school_admins') {
                $query->whereIn(DB::raw('LOWER(users.role)'), ['admin', 'owner', 'proprietor', 'principal', 'operator', 'bursar']);
            } elseif (strtolower($role) === 'teachers') {
                $query->whereIn(DB::raw('LOWER(users.role)'), ['teacher', 'class_teacher', 'subject_teacher']);
            } else {
                $query->whereRaw('LOWER(users.role) = ?', [strtolower($role)]);
            }
        }

        // School filter
        if ($schoolId = $request->input('school_id')) {
            if ($schoolId === 'platform') {
                $query->whereNull('users.school_id');
            } elseif (is_numeric($schoolId)) {
                $query->where('users.school_id', (int) $schoolId);
            }
        }

        // Status filter
        if ($request->has('status') && $request->input('status') !== '' && $request->input('status') !== 'all') {
            $query->where('users.status', (int) $request->input('status'));
        }

        // Total Counts for KPI cards
        $totalUsers = User::count();
        $platformStaffCount = User::whereRaw("LOWER(REPLACE(REPLACE(role, '-', ''), ' ', '')) in ('superadmin', 'platformstaff')")->count();
        $schoolAdminsCount = User::whereIn(DB::raw('LOWER(role)'), ['admin', 'owner', 'proprietor', 'principal', 'operator', 'bursar'])->count();
        $teachersCount = User::whereIn(DB::raw('LOWER(role)'), ['teacher', 'class_teacher', 'subject_teacher'])->count();
        $activeCount = User::where('status', 1)->count();
        $suspendedCount = User::where('status', 0)->count();

        // Paginate users
        $perPage = min(max((int) $request->input('per_page', 15), 5), 100);
        $paginator = $query->orderBy('users.id', 'desc')->paginate($perPage);

        $users = $paginator->getCollection()->map(function (User $u) {
            $activePermissions = $u->superAdminPermissions();
            return [
                'id' => $u->id,
                'firstname' => $u->firstname,
                'surname' => $u->surname,
                'name' => trim("{$u->firstname} {$u->surname}") ?: ($u->name ?: $u->email),
                'email' => $u->email,
                'phone' => $u->phone,
                'reg_no' => $u->reg_no,
                'role' => $u->role,
                'normalized_role' => User::normalizeRole($u->role),
                'school_id' => $u->school_id,
                'school_name' => $u->school_name ?: ($u->school_id ? "School #{$u->school_id}" : 'Platform HQ'),
                'is_platform_staff' => $u->isSuperAdminUser(),
                'status' => (int) $u->status,
                'super_admin_type' => $u->super_admin_type ?: 'owner',
                'super_admin_type_label' => $u->superAdminTypeLabel(),
                'permissions' => $activePermissions,
                'created_at' => $u->created_at?->toDateTimeString(),
            ];
        });

        // Schools list for dropdown
        $schools = SchoolSetting::select('id', 'school_name')
            ->orderBy('school_name', 'asc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'current_page' => $paginator->currentPage(),
                'data' => $users,
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
            ],
            'meta' => [
                'available_permissions' => self::AVAILABLE_PERMISSIONS,
                'available_roles' => self::AVAILABLE_ROLES,
                'schools' => $schools,
                'counts' => [
                    'total_users' => $totalUsers,
                    'platform_staff' => $platformStaffCount,
                    'school_admins' => $schoolAdminsCount,
                    'teachers' => $teachersCount,
                    'active_users' => $activeCount,
                    'suspended_users' => $suspendedCount,
                ],
            ],
        ]);
    }

    /**
     * Update user role.
     */
    public function updateRole(Request $request, User $user)
    {
        $validated = $request->validate([
            'role' => ['required', 'string', 'max:50'],
        ]);

        $newRole = trim($validated['role']);
        $currentUser = $request->user();

        // Protection: ensure primary owner account is not accidentally demoted
        if (strtolower(str_replace(['-', ' ', '_'], '', (string) $user->role)) === 'superadmin' &&
            strtolower(str_replace(['-', ' ', '_'], '', $newRole)) !== 'superadmin') {
            $ownersCount = User::whereRaw("LOWER(REPLACE(REPLACE(role, '-', ''), ' ', '')) = 'superadmin'")->count();
            if ($ownersCount <= 1) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot demote the sole platform Super Admin owner account.',
                ], 422);
            }
        }

        $oldRole = $user->role;
        $user->role = $newRole;

        // Auto-configure super_admin_type if assigned to platform staff
        if (in_array(strtolower($newRole), ['super-admin', 'super_admin', 'superadmin'], true)) {
            $user->super_admin_type = 'owner';
        } elseif (in_array(strtolower($newRole), ['platform-staff', 'platform_staff', 'platformstaff'], true)) {
            if (!$user->super_admin_type || $user->super_admin_type === 'owner') {
                $user->super_admin_type = 'operations';
            }
        }

        $user->save();

        // Sync Spatie role if available
        try {
            $spatieRole = Role::firstOrCreate(['name' => $newRole, 'guard_name' => 'web']);
            $user->syncRoles([$spatieRole]);
        } catch (\Throwable) {}

        // Log action
        try {
            ActivityLog::create([
                'user_id' => $currentUser->id,
                'school_id' => $user->school_id,
                'action' => 'update_user_role',
                'description' => "Changed role of {$user->email} from '{$oldRole}' to '{$newRole}'",
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        } catch (\Throwable) {}

        return response()->json([
            'status' => 'success',
            'message' => "Role for {$user->firstname} {$user->surname} successfully updated to '{$newRole}'.",
            'user' => [
                'id' => $user->id,
                'role' => $user->role,
                'normalized_role' => User::normalizeRole($user->role),
                'permissions' => $user->superAdminPermissions(),
            ],
        ]);
    }

    /**
     * Toggle user status (Active = 1, Suspended = 0).
     */
    public function toggleStatus(Request $request, User $user)
    {
        $currentUser = $request->user();

        if ((int) $currentUser->id === (int) $user->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'You cannot suspend or deactivate your own administrator account.',
            ], 422);
        }

        $newStatus = $request->has('status') ? ($request->boolean('status') ? 1 : 0) : ($user->status ? 0 : 1);
        $user->status = $newStatus;
        $user->save();

        $statusText = $newStatus ? 'Activated' : 'Suspended';

        // Log action
        try {
            ActivityLog::create([
                'user_id' => $currentUser->id,
                'school_id' => $user->school_id,
                'action' => 'toggle_user_status',
                'description' => "{$statusText} account for user: {$user->email}",
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        } catch (\Throwable) {}

        return response()->json([
            'status' => 'success',
            'message' => "Account for {$user->firstname} {$user->surname} is now {$statusText}.",
            'status_value' => $newStatus,
        ]);
    }

    /**
     * Bulk update all permissions for a user.
     */
    public function updatePermissions(Request $request, User $user)
    {
        $validated = $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string'],
        ]);

        $permissions = array_values(array_unique(array_filter($validated['permissions'])));
        $user->super_admin_permissions = $permissions;
        $user->save();

        $currentUser = $request->user();

        // Log action
        try {
            ActivityLog::create([
                'user_id' => $currentUser->id,
                'school_id' => $user->school_id,
                'action' => 'update_user_permissions',
                'description' => "Updated permissions for {$user->email} (" . count($permissions) . " permissions enabled)",
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        } catch (\Throwable) {}

        return response()->json([
            'status' => 'success',
            'message' => "Permissions for {$user->firstname} {$user->surname} have been updated successfully.",
            'permissions' => $user->superAdminPermissions(),
        ]);
    }

    /**
     * Toggle a single permission on or off for a user.
     */
    public function toggleSinglePermission(Request $request, User $user)
    {
        $validated = $request->validate([
            'permission' => ['required', 'string'],
            'enabled' => ['required', 'boolean'],
        ]);

        $perm = trim($validated['permission']);
        $enabled = (bool) $validated['enabled'];

        $currentPerms = $user->superAdminPermissions();

        if ($enabled) {
            if (!in_array($perm, $currentPerms, true)) {
                $currentPerms[] = $perm;
            }
        } else {
            $currentPerms = array_values(array_filter($currentPerms, fn ($p) => $p !== $perm));
        }

        $user->super_admin_permissions = array_values(array_unique($currentPerms));
        $user->save();

        $actionText = $enabled ? 'granted to' : 'revoked from';

        return response()->json([
            'status' => 'success',
            'message' => "Permission '{$perm}' was {$actionText} {$user->firstname} {$user->surname}.",
            'permission' => $perm,
            'enabled' => $enabled,
            'permissions' => $user->superAdminPermissions(),
        ]);
    }

    /**
     * Send temporary login password.
     */
    public function sendLoginDetails(Request $request, User $user)
    {
        $plainPassword = 'SP-' . Str::upper(Str::random(8));

        $user->password = Hash::make($plainPassword);
        $user->default_password = $plainPassword;
        $user->force_password_change = true;
        $user->save();

        $frontendUrl = rtrim((string) config('app.frontend_url', env('FRONTEND_URL', config('app.url'))), '/') . '/login';

        try {
            Mail::to($user->email)->send(new \App\Mail\PlatformStaffLoginMail($user, $plainPassword, $frontendUrl));
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'status' => 'success',
            'message' => "New login password generated and dispatched to {$user->email}.",
            'default_password' => $plainPassword,
        ]);
    }

    /**
     * Create new user with role and permissions.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'firstname' => ['required', 'string', 'max:100'],
            'surname' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:50'],
            'role' => ['required', 'string', 'max:50'],
            'school_id' => ['nullable', 'numeric'],
            'status' => ['nullable', 'boolean'],
            'permissions' => ['nullable', 'array'],
        ]);

        $plainPassword = 'SP-' . Str::upper(Str::random(8));
        $role = trim($validated['role']);

        $superAdminType = null;
        if (in_array(strtolower($role), ['super-admin', 'super_admin', 'superadmin'], true)) {
            $superAdminType = 'owner';
        } elseif (in_array(strtolower($role), ['platform-staff', 'platform_staff', 'platformstaff'], true)) {
            $superAdminType = 'operations';
        }

        $permissions = !empty($validated['permissions']) ? array_values(array_unique($validated['permissions'])) : null;

        $user = User::create([
            'firstname' => trim($validated['firstname']),
            'surname' => trim($validated['surname'] ?? ''),
            'email' => strtolower(trim($validated['email'])),
            'phone' => trim($validated['phone'] ?? ''),
            'role' => $role,
            'school_id' => $validated['school_id'] ?? null,
            'super_admin_type' => $superAdminType,
            'super_admin_permissions' => $permissions,
            'status' => $request->boolean('status', true) ? 1 : 0,
            'password' => Hash::make($plainPassword),
            'default_password' => $plainPassword,
            'force_password_change' => true,
        ]);

        try {
            $spatieRole = Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
            $user->assignRole($spatieRole);
        } catch (\Throwable) {}

        $frontendUrl = rtrim((string) config('app.frontend_url', env('FRONTEND_URL', config('app.url'))), '/') . '/login';

        try {
            Mail::to($user->email)->send(new \App\Mail\PlatformStaffLoginMail($user, $plainPassword, $frontendUrl));
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'status' => 'success',
            'message' => "User account created and login instructions dispatched to {$user->email}.",
            'default_password' => $plainPassword,
            'user' => [
                'id' => $user->id,
                'name' => "{$user->firstname} {$user->surname}",
                'email' => $user->email,
                'role' => $user->role,
                'status' => (int) $user->status,
                'permissions' => $user->superAdminPermissions(),
            ],
        ], 201);
    }
}
