<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Subscription;
use App\Models\SchoolSetting;
use App\Models\ActivityLog;
use App\Models\SchoolBillingAuditLog;
use App\Models\SchoolBankAccount;
use App\Mail\MarketingEmail;
use App\Models\SchoolDomain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SuperAdminController extends Controller
{
    /**
     * Get subscribers / schools listing with pricing tier information.
     */
    public function getSubscribers(Request $request)
    {
        return $this->getAdminUsers($request);
    }

    /**
     * Get full list of platform features.
     */
    public function getUserFeatures(Request $request)
    {
        $user = $request->user();
        $schoolSetting = $user ? ($user->school ?: $user->schoolsetting) : null;
        if ($user && $user->school_id && ! $schoolSetting) {
            $schoolSetting = SchoolSetting::find($user->school_id);
        }
        $activeTier = strtolower((string) ($schoolSetting?->active_edition_tier ?: 'standard_cbt'));

        $allFeatures = [
            'student_management',
            'support_student_management',
            'teacher_management',
            'support_teacher_management',
            'result_management',
            'support_results_upload',
            'fee_management',
            'support_fee_management',
            'online_payment',
            'attendance_management',
            'support_student_attendance',
            'support_teacher_attendance',
            'staff_attendance',
            'support_staff_attendance',
            'parent_management',
            'support_parent_management',
            'bursar_management',
            'support_bursar_management',
            'settings_management',
            'support_settings_management',
            'support_broadsheet',
            'support_student_promotion',
            'support_parent_timetable',
            'ai_lesson_plan_generator',
            'ai_fee_collection_assistant',
            'ai_result_comment_generator',
            'gradequest_plus',
            'whatsapp_notifications',
            'whatsapp_messaging',
            'hostel_management',
            'transport_management',
            'fees',
            'results',
        ];

        // CBT and AI CBT Question Generator are only available to full CBT editions (standard_cbt, annual_full_session) or platform staff / super admins
        if ($user && ($user->isSuperAdminUser() || $activeTier !== 'basic_result')) {
            $allFeatures[] = 'cbt_online';
            $allFeatures[] = 'cbt_offline';
            $allFeatures[] = 'cbt';
            $allFeatures[] = 'ai_cbt_question_generator';
        }

        return response()->json([
            'features' => $allFeatures,
            'active_edition_tier' => $activeTier,
        ]);
    }

    /**
     * Get registered School Owners / Administrators with pricing editions and online payment status.
     */
    public function getAdminUsers(Request $request)
    {
        $user = $request->user();

        if (!$user || !$user->isSuperAdminUser()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $perPage = (int) $request->get('perPage', $request->get('per_page', 10));
        $page = (int) $request->get('page', 1);
        $search = trim((string) $request->input('search', ''));
        $tier = trim((string) $request->input('tier', $request->input('edition_tier', '')));
        $onlinePay = $request->input('online_payment', $request->input('online_pay'));
        $status = $request->input('status');

        $adminsQuery = User::where(function ($q) {
            $q->whereIn(DB::raw('LOWER(role)'), ['admin', 'owner', 'proprietor'])
              ->orWhereHas('roles', function ($rq) {
                  $rq->whereIn('name', ['Admin', 'admin']);
              });
        })
        ->where(function ($q) {
            $q->whereNull('school_id')
              ->orWhere('school_id', '!=', 68);
        })
        ->where('email', '!=', 'gradequestapp@gmail.com')
        ->whereDoesntHave('school', function ($q) {
            $q->where('school_name', 'like', '%gradequest international%');
        });

        // Search by name, email, phone, or school name
        if ($search !== '') {
            $adminsQuery->where(function ($query) use ($search) {
                $query->where('firstname', 'like', "%{$search}%")
                      ->orWhere('surname', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%")
                      ->orWhereHas('school', function ($sq) use ($search) {
                          $sq->where('school_name', 'like', "%{$search}%")
                             ->orWhere('phone', 'like', "%{$search}%")
                             ->orWhere('email', 'like', "%{$search}%");
                      });
            });
        }

        // Filter by pricing edition tier
        if ($tier !== '' && $tier !== 'all') {
            $adminsQuery->whereHas('school', function ($sq) use ($tier) {
                $sq->where('active_edition_tier', $tier);
            });
        }

        // Filter by online payment switch status
        if ($onlinePay !== null && $onlinePay !== '' && $onlinePay !== 'all') {
            $isEnabled = in_array(strval($onlinePay), ['1', 'true', 'enabled', 'on'], true);
            $adminsQuery->whereHas('school', function ($sq) use ($isEnabled) {
                $sq->where('online_payment_enabled', $isEnabled ? 1 : 0);
            });
        }

        // Filter by user account status
        if ($status !== null && $status !== '' && $status !== 'all') {
            $isActive = in_array(strval($status), ['1', 'active', 'true'], true);
            $adminsQuery->where('status', $isActive ? 1 : 0);
        }

        $adminsQuery->with(['roles', 'school'])
                    ->orderBy('created_at', 'desc');

        $admins = $adminsQuery->paginate($perPage, ['*'], 'page', $page);

        // Transform collection to add rich SchoolProfit edition and stats
        $admins->getCollection()->transform(function ($admin) {
            $studentCount = 0;
            if ($admin->school_id) {
                $studentCount = User::where('school_id', $admin->school_id)
                    ->whereRaw('LOWER(role) = ?', ['student'])
                    ->count();
            }

            $activeTier = $admin->school?->active_edition_tier ?: 'standard_cbt';
            $tierLabel = match ($activeTier) {
                'basic_result' => 'Basic Result Edition',
                'annual_full_session' => 'Annual Full Session Tier',
                default => 'Standard CBT & AI Edition',
            };

            return [
                'id' => $admin->id,
                'firstname' => $admin->firstname,
                'surname' => $admin->surname,
                'name' => $admin->name,
                'email' => $admin->email,
                'phone' => $admin->phone,
                'address' => $admin->address,
                'status' => $admin->status,
                'role' => $admin->role,
                'school_id' => $admin->school_id,
                'student_count' => $studentCount,
                'active_edition_tier' => $activeTier,
                'active_edition_tier_label' => $tierLabel,
                'online_payment_enabled' => (bool) ($admin->school?->online_payment_enabled ?? true),
                'created_at' => $admin->created_at?->toIso8601String(),
                'school' => $admin->school ? [
                    'id' => $admin->school->id,
                    'school_name' => $admin->school->school_name,
                    'active_edition_tier' => $activeTier,
                    'active_edition_tier_label' => $tierLabel,
                    'online_payment_enabled' => (bool) ($admin->school->online_payment_enabled ?? true),
                    'email' => $admin->school->email,
                    'phone' => $admin->school->phone,
                    'address' => $admin->school->address,
                    'created_at' => $admin->school->created_at?->toIso8601String(),
                ] : null,
                'plan' => [
                    'id' => 1,
                    'name' => $tierLabel,
                    'tier' => $activeTier,
                ],
                'user' => [
                    'id' => $admin->id,
                    'firstname' => $admin->firstname,
                    'surname' => $admin->surname,
                    'name' => $admin->name,
                    'email' => $admin->email,
                    'phone' => $admin->phone,
                    'school_id' => $admin->school_id,
                ],
            ];
        });

        // Compute tier totals for dashboard cards
        $totalQuery = User::where(function ($q) {
            $q->whereIn(DB::raw('LOWER(role)'), ['admin', 'owner', 'proprietor'])
              ->orWhereHas('roles', function ($rq) {
                  $rq->whereIn('name', ['Admin', 'admin']);
              });
        })
        ->where(function ($q) {
            $q->whereNull('school_id')
              ->orWhere('school_id', '!=', 68);
        })
        ->where('email', '!=', 'gradequestapp@gmail.com')
        ->whereDoesntHave('school', function ($q) {
            $q->where('school_name', 'like', '%gradequest international%');
        });

        $totalSchools = (clone $totalQuery)->count();
        $standardCbtCount = (clone $totalQuery)->whereHas('school', fn($s) => $s->where('active_edition_tier', 'standard_cbt'))->count();
        $basicResultCount = (clone $totalQuery)->whereHas('school', fn($s) => $s->where('active_edition_tier', 'basic_result'))->count();
        $annualSessionCount = (clone $totalQuery)->whereHas('school', fn($s) => $s->where('active_edition_tier', 'annual_full_session'))->count();
        $onlinePayEnabledCount = (clone $totalQuery)->whereHas('school', fn($s) => $s->where('online_payment_enabled', 1))->count();

        $response = $admins->toArray();
        $response['tier_counts'] = [
            'total' => $totalSchools,
            'standard_cbt' => $standardCbtCount,
            'basic_result' => $basicResultCount,
            'annual_full_session' => $annualSessionCount,
            'online_pay_enabled' => $onlinePayEnabledCount,
            'online_pay_disabled' => max(0, $totalSchools - $onlinePayEnabledCount),
        ];

        return response()->json($response);
    }

    /**
     * Show single administrator details with school info, edition tier, and billing history.
     */
    public function showAdmin($id)
    {
        $auth = request()->user();

        if (!$auth || !$auth->isSuperAdminUser()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $admin = User::with('school')->findOrFail($id);

        $studentCount = 0;
        if ($admin->school_id) {
            $studentCount = User::where('school_id', $admin->school_id)
                ->whereRaw('LOWER(role) = ?', ['student'])
                ->count();
        }

        $activeTier = $admin->school?->active_edition_tier ?: 'standard_cbt';
        $tierLabel = match ($activeTier) {
            'basic_result' => 'Basic Result Edition',
            'annual_full_session' => 'Annual Full Session Tier',
            default => 'Standard CBT & AI Edition',
        };

        // Subscription + Plan
        $subscription = \App\Models\Subscription::with('plan')
            ->where('user_id', $admin->id)
            ->latest('created_at')
            ->first();

        // Payments + Plan (billing history)
        $payments = \App\Models\SubPayment::with('plan')
            ->where('user_id', $admin->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($p) {
                return [
                    'id' => $p->id,
                    'reference' => $p->reference,
                    'amount' => (float) $p->amount,
                    'status' => $p->status,
                    'channel' => $p->channel,
                    'card_type' => $p->card_type,
                    'last4' => $p->last4,
                    'starts_at' => $p->starts_at,
                    'created_at' => $p->created_at,
                    'plan' => $p->plan ? [
                        'id' => $p->plan->id,
                        'name' => $p->plan->name,
                        'price' => $p->plan->price,
                        'duration_in_days' => $p->plan->duration_in_days,
                    ] : null,
                ];
            });

        return response()->json([
            'admin' => $admin,
            'student_count' => $studentCount,
            'active_edition_tier' => $activeTier,
            'active_edition_tier_label' => $tierLabel,
            'online_payment_enabled' => (bool) ($admin->school?->online_payment_enabled ?? true),
            'billing' => [
                'subscription' => $subscription ? [
                    'id' => $subscription->id,
                    'status' => $subscription->status,
                    'auto_renew' => (bool) $subscription->auto_renew,
                    'auto_renew_source' => $subscription->auto_renew_source,
                    'starts_at' => $subscription->starts_at,
                    'ends_at' => $subscription->ends_at,
                    'plan' => $subscription->plan ? [
                        'id' => $subscription->plan->id,
                        'name' => $subscription->plan->name,
                        'price' => $subscription->plan->price,
                        'duration_in_days' => $subscription->plan->duration_in_days,
                    ] : null,
                ] : null,
                'payments' => $payments,
            ],
        ]);
    }

    public function edit($id)
    {
        return User::with('school')->findOrFail($id);
    }

    public function update(Request $request, $id)
    {
        return $this->updateAdminProfile($request, $id);
    }

    public function updateAdminProfile(Request $request, $id)
    {
        $auth = $request->user();
        if (!$auth || !$auth->isSuperAdminUser()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $admin = User::with('school')->findOrFail($id);

        $validated = $request->validate([
            'firstname' => 'required|string|max:100',
            'surname' => 'required|string|max:100',
            'email' => 'required|email|unique:users,email,' . $admin->id,
            'phone' => 'nullable|string|max:30',
            'address' => 'nullable|string|max:255',
            'school_name' => 'nullable|string|max:255',
            'active_edition_tier' => 'nullable|string|in:basic_result,standard_cbt,annual_full_session',
            'online_payment_enabled' => 'nullable|boolean',
        ]);

        $admin->update([
            'firstname' => $validated['firstname'],
            'surname' => $validated['surname'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? $admin->phone,
            'address' => $validated['address'] ?? $admin->address,
        ]);

        if ($admin->school) {
            $schoolUpdates = [];
            if (!empty($validated['school_name'])) {
                $schoolUpdates['school_name'] = $validated['school_name'];
            }
            if (isset($validated['active_edition_tier'])) {
                $schoolUpdates['active_edition_tier'] = $validated['active_edition_tier'];
            }
            if (isset($validated['online_payment_enabled'])) {
                $schoolUpdates['online_payment_enabled'] = $validated['online_payment_enabled'];
            }
            if (!empty($schoolUpdates)) {
                $admin->school->update($schoolUpdates);
            }
        }

        return response()->json([
            'message' => 'Administrator and school profile updated successfully.',
            'admin' => $admin->fresh(['school']),
        ]);
    }

    /**
     * Toggle school online fee payment by Admin User ID.
     */
    public function toggleSchoolOnlinePayment(Request $request, $id)
    {
        $auth = $request->user();
        if (!$auth || !$auth->isSuperAdminUser()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $admin = User::with('school')->findOrFail($id);
        $school = $admin->school;

        if (!$school) {
            return response()->json(['message' => 'No school linked to this administrator account.'], 404);
        }

        $desiredState = $request->has('enabled')
            ? filter_var($request->input('enabled'), FILTER_VALIDATE_BOOLEAN)
            : !$school->online_payment_enabled;

        $school->update([
            'online_payment_enabled' => $desiredState,
        ]);

        return response()->json([
            'success' => true,
            'message' => $desiredState
                ? "Online fee payment enabled for {$school->school_name}. Parents can now pay online."
                : "Online fee payment disabled for {$school->school_name}. Online fee payments will now be rejected by the system.",
            'online_payment_enabled' => (bool) $desiredState,
            'school' => $school,
        ]);
    }

    /**
     * Toggle school online fee payment directly by School ID.
     */
    public function toggleSchoolOnlinePaymentById(Request $request, $schoolId)
    {
        $auth = $request->user();
        if (!$auth || !$auth->isSuperAdminUser()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $school = SchoolSetting::findOrFail($schoolId);

        $desiredState = $request->has('enabled')
            ? filter_var($request->input('enabled'), FILTER_VALIDATE_BOOLEAN)
            : !$school->online_payment_enabled;

        $school->update([
            'online_payment_enabled' => $desiredState,
        ]);

        return response()->json([
            'success' => true,
            'message' => $desiredState
                ? "Online fee payment enabled for {$school->school_name}. Parents can now pay online."
                : "Online fee payment disabled for {$school->school_name}. Online fee payments will now be rejected by the system.",
            'online_payment_enabled' => (bool) $desiredState,
            'school' => $school,
        ]);
    }

    /**
     * Bulk enable or disable online fee payment for ALL schools at once.
     */
    public function bulkToggleSchoolOnlinePayment(Request $request)
    {
        $auth = $request->user();
        if (!$auth || !$auth->isSuperAdminUser()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'enabled' => 'required|boolean',
            'reason' => 'nullable|string|max:500',
        ]);

        $desiredState = (bool) $validated['enabled'];
        $reason = trim((string) ($validated['reason'] ?? '')) ?: ($desiredState ? 'Bulk enabled for all schools by SuperAdmin' : 'Bulk disabled for all schools by SuperAdmin');

        $schools = SchoolSetting::all();
        $updatedCount = $schools->count();

        SchoolSetting::query()->update([
            'online_payment_enabled' => $desiredState,
            'updated_at' => now(),
        ]);

        // If disabling, also ensure bank accounts reflect the disabled status
        if (!$desiredState) {
            SchoolBankAccount::withoutGlobalScopes()->update([
                'online_payment_enabled' => false,
            ]);
        }

        // Audit log entry per existing school to satisfy foreign key constraint
        foreach ($schools as $school) {
            try {
                SchoolBillingAuditLog::create([
                    'school_id' => $school->id,
                    'actor_id' => $auth->id,
                    'action' => $desiredState ? 'bulk_online_fee_payment_enabled' : 'bulk_online_fee_payment_disabled',
                    'auditable_type' => SchoolSetting::class,
                    'auditable_id' => $school->id,
                    'after' => [
                        'online_payment_enabled' => $desiredState,
                    ],
                    'reason' => $reason,
                ]);
            } catch (\Throwable $e) {
                // Ignore audit log failure for edge-case school records
            }
        }

        return response()->json([
            'success' => true,
            'message' => $desiredState
                ? "Online fee payment has been successfully ENABLED for all {$updatedCount} schools. Parents can now pay school fees online across the platform."
                : "Online fee payment has been successfully DISABLED for all {$updatedCount} schools. Online fee payment attempts will now be rejected platform-wide.",
            'online_payment_enabled' => $desiredState,
            'affected_schools_count' => $updatedCount,
        ]);
    }

    /**
     * Update active edition tier for a school.
     */
    public function updateSchoolEditionTier(Request $request, $schoolId)
    {
        $auth = $request->user();
        if (!$auth || !$auth->isSuperAdminUser()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'tier' => 'required|string|in:basic_result,standard_cbt,annual_full_session',
        ]);

        $school = SchoolSetting::findOrFail($schoolId);
        $school->update([
            'active_edition_tier' => $validated['tier'],
        ]);

        $tierLabel = match ($validated['tier']) {
            'basic_result' => 'Basic Result Edition',
            'annual_full_session' => 'Annual Full Session Tier',
            default => 'Standard CBT & AI Edition',
        };

        return response()->json([
            'success' => true,
            'message' => "School edition tier updated to {$tierLabel} successfully.",
            'tier' => $validated['tier'],
            'school' => $school,
        ]);
    }

    public function toggleAdminStatus($id)
    {
        $auth = request()->user();
        if (!$auth || !$auth->isSuperAdminUser()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $admin = User::findOrFail($id);

        if ($admin->id === $auth->id) {
            return response()->json(['message' => 'You cannot change your own account status.'], 400);
        }

        $admin->status = ($admin->status == 1) ? 0 : 1;
        $admin->save();

        return response()->json([
            'message' => $admin->status == 1 ? 'Administrator account activated.' : 'Administrator account suspended.',
            'status' => $admin->status,
        ]);
    }

    public function resetAdminPassword(Request $request, $id)
    {
        $auth = $request->user();
        if (!$auth || !$auth->isSuperAdminUser()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $admin = User::findOrFail($id);

        $request->validate([
            'password' => 'required|string|min:6',
        ]);

        $admin->update([
            'password' => Hash::make($request->password),
        ]);

        return response()->json([
            'message' => 'Password reset successfully for ' . $admin->name,
        ]);
    }

    public function destroy($id)
    {
        $auth = request()->user();
        if (!$auth || !$auth->isSuperAdminUser()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $admin = User::findOrFail($id);

        if ($admin->id === $auth->id) {
            return response()->json(['message' => 'You cannot delete your own super admin account.'], 400);
        }

        $admin->delete();

        return response()->json([
            'message' => 'Administrator account deleted successfully.',
        ]);
    }

    public function getLogs(Request $request)
    {
        $perPage = $request->input('per_page', 10);

        $logs = ActivityLog::with('user:id,firstname,surname')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        $logs->getCollection()->transform(function ($log) {
            return [
                'id' => $log->id,
                'user_id' => $log->user_id,
                'user_name' => $log->user ? $log->user->name : 'System',
                'action' => $log->action,
                'description' => $log->description,
                'ip_address' => $log->ip_address,
                'user_agent' => $log->user_agent,
                'created_at' => $log->created_at->toDateTimeString(),
            ];
        });

        return response()->json($logs);
    }

    public function sendMarketingEmail(Request $request)
    {
        $request->validate([
            'subject' => 'required|string',
            'content' => 'required|string',
            'recipients' => 'required|array',
        ]);

        $user = $request->user();
        if (!$user || !$user->isSuperAdminUser()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        foreach ($request->recipients as $recipient) {
            $email = $recipient['email'] ?? null;
            $firstname = $recipient['firstname'] ?? 'dear Sir/Ma';

            if (!$email) continue;

            try {
                $personalizedContent = str_replace('{firstname}', $firstname, $request->content);
                Mail::to($email)->send(new MarketingEmail($request->subject, $personalizedContent));
            } catch (\Exception $e) {
                Log::error("Failed to send marketing email to " . json_encode($recipient) . ": " . $e->getMessage());
            }
        }

        return response()->json(['message' => 'Emails sent successfully']);
    }

    public function mailAdminUsers()
    {
        $users = User::where(function ($q) {
            $q->whereIn(DB::raw('LOWER(role)'), ['admin', 'owner', 'proprietor'])
              ->orWhereHas('roles', function ($rq) {
                  $rq->whereIn('name', ['Admin', 'admin']);
              });
        })
        ->where(function ($q) {
            $q->whereNull('school_id')
              ->orWhere('school_id', '!=', 68);
        })
        ->where('email', '!=', 'gradequestapp@gmail.com')
        ->whereDoesntHave('school', function ($q) {
            $q->where('school_name', 'like', '%gradequest international%');
        })
        ->with('school')
        ->get(['id', 'firstname', 'surname', 'email', 'status', 'school_id'])
        ->map(function ($u) {
            $tier = $u->school?->active_edition_tier ?: 'standard_cbt';
            $tierLabel = match ($tier) {
                'basic_result' => 'Basic Result Edition',
                'annual_full_session' => 'Annual Full Session Tier',
                default => 'Standard CBT & AI Edition',
            };

            return [
                'id' => $u->id,
                'firstname' => $u->firstname,
                'surname' => $u->surname,
                'email' => $u->email,
                'status' => $u->status,
                'tier' => $tier,
                'plan_name' => $tierLabel,
                'subscription_status' => 'active',
                'subscription_starts_at' => $u->school?->created_at,
                'subscription_ends_at' => null,
                'school' => $u->school ? [
                    'id' => $u->school->id ?? null,
                    'school_name' => $u->school->school_name ?? null,
                    'email' => $u->school->email ?? null,
                    'phone' => $u->school->phone ?? null,
                    'address' => $u->school->address ?? null,
                    'online_payment_enabled' => (bool) ($u->school->online_payment_enabled ?? true),
                ] : null,
            ];
        });

        $newsletterSubscribers = \App\Models\NewsletterSubscriber::subscribed()->get()->map(function ($ns) {
            return [
                'id' => $ns->id,
                'firstname' => 'Subscriber',
                'surname' => '',
                'email' => $ns->email,
                'status' => 'subscribed',
                'tier' => 'newsletter',
                'plan_name' => 'Newsletter Lead (' . ($ns->source ?: 'footer') . ')',
                'subscription_status' => 'subscribed',
                'subscription_starts_at' => $ns->created_at,
                'subscription_ends_at' => null,
                'school' => null,
            ];
        });

        return response()->json([
            'users' => $users->concat($newsletterSubscribers)
        ]);
    }

    public function monthlyRevenueStats()
    {
        $year = now()->year;

        // Helper to map a date to Nigerian school terms
        $getTermFromDate = function ($dateStr) {
            if (!$dateStr) return '1st Term';
            $m = (int) date('n', strtotime($dateStr));
            if ($m >= 9 && $m <= 12) return '1st Term';
            if ($m >= 1 && $m <= 4) return '2nd Term';
            return '3rd Term';
        };

        // 1. Subscriptions (sub_payments)
        // Strictly exclude GradeQuest School (school_id = 68, gradequestapp@gmail.com, or school_name like %gradequest%)
        $subscriptions = DB::table('sub_payments')
            ->leftJoin('users', 'sub_payments.user_id', '=', 'users.id')
            ->leftJoin('school_settings', 'users.school_id', '=', 'school_settings.id')
            ->where('sub_payments.status', 'successful')
            ->where(function ($q) {
                $q->whereNull('users.school_id')->orWhere('users.school_id', '!=', 68);
            })
            ->where(function ($q) {
                $q->whereNull('school_settings.school_name')->orWhere('school_settings.school_name', 'not like', '%gradequest%');
            })
            ->where(function ($q) {
                $q->whereNull('users.email')->orWhere('users.email', '!=', 'gradequestapp@gmail.com');
            })
            ->selectRaw("
                sub_payments.id,
                sub_payments.amount,
                COALESCE(sub_payments.paid_at, sub_payments.created_at) as trans_date
            ")
            ->get()
            ->map(function ($r) use ($getTermFromDate) {
                $date = $r->trans_date ?: now()->toDateTimeString();
                return [
                    'category' => 'subscriptions',
                    'amount' => (float) $r->amount,
                    'date' => $date,
                    'year' => (int) date('Y', strtotime($date)),
                    'month_number' => (int) date('n', strtotime($date)),
                    'month' => date('F', strtotime($date)),
                    'month_year' => date('M Y', strtotime($date)),
                    'month_key' => date('Y-m', strtotime($date)),
                    'term' => $getTermFromDate($date),
                ];
            });

        // 2. Custom Domain Orders (school_domain_orders)
        $domainOrders = collect();
        if (Schema::hasTable('school_domain_orders')) {
            $domainOrders = DB::table('school_domain_orders')
                ->leftJoin('school_settings', 'school_domain_orders.school_id', '=', 'school_settings.id')
                ->whereIn('school_domain_orders.status', ['active', 'paid', 'completed', 'success'])
                ->where(function ($q) {
                    $q->whereNull('school_domain_orders.school_id')->orWhere('school_domain_orders.school_id', '!=', 68);
                })
                ->where(function ($q) {
                    $q->whereNull('school_settings.school_name')->orWhere('school_settings.school_name', 'not like', '%gradequest%');
                })
                ->selectRaw("
                    school_domain_orders.id,
                    school_domain_orders.amount,
                    COALESCE(school_domain_orders.paid_at, school_domain_orders.created_at) as trans_date
                ")
                ->get()
                ->map(function ($r) use ($getTermFromDate) {
                    $date = $r->trans_date ?: now()->toDateTimeString();
                    return [
                        'category' => 'domains',
                        'amount' => (float) $r->amount,
                        'date' => $date,
                        'year' => (int) date('Y', strtotime($date)),
                        'month_number' => (int) date('n', strtotime($date)),
                        'month' => date('F', strtotime($date)),
                        'month_year' => date('M Y', strtotime($date)),
                        'month_key' => date('Y-m', strtotime($date)),
                        'term' => $getTermFromDate($date),
                    ];
                });
        }

        // 3. Online Platform Tech Royalty Fees on Tuition (payments)
        $payments = DB::table('payments')
            ->leftJoin('school_settings', 'payments.school_id', '=', 'school_settings.id')
            ->where('payments.status', 'success')
            ->where(function ($q) {
                $q->whereNull('payments.school_id')->orWhere('payments.school_id', '!=', 68);
            })
            ->where(function ($q) {
                $q->whereNull('school_settings.school_name')->orWhere('school_settings.school_name', 'not like', '%gradequest%');
            })
            ->selectRaw("
                payments.id,
                payments.amount as gmv,
                payments.platform_fee,
                payments.created_at as trans_date
            ")
            ->get()
            ->map(function ($r) use ($getTermFromDate) {
                $date = $r->trans_date ?: now()->toDateTimeString();
                return [
                    'category' => 'tech_fees',
                    'amount' => (float) $r->platform_fee,
                    'gmv' => (float) $r->gmv,
                    'date' => $date,
                    'year' => (int) date('Y', strtotime($date)),
                    'month_number' => (int) date('n', strtotime($date)),
                    'month' => date('F', strtotime($date)),
                    'month_year' => date('M Y', strtotime($date)),
                    'month_key' => date('Y-m', strtotime($date)),
                    'term' => $getTermFromDate($date),
                ];
            });

        // 4. Offline Invoices (gradequest_invoice_payments)
        $invoices = collect();
        if (Schema::hasTable('gradequest_invoice_payments')) {
            $invoices = DB::table('gradequest_invoice_payments')
                ->leftJoin('school_settings', 'gradequest_invoice_payments.school_id', '=', 'school_settings.id')
                ->whereIn('gradequest_invoice_payments.status', ['success', 'successful', 'paid'])
                ->where(function ($q) {
                    $q->whereNull('gradequest_invoice_payments.school_id')->orWhere('gradequest_invoice_payments.school_id', '!=', 68);
                })
                ->where(function ($q) {
                    $q->whereNull('school_settings.school_name')->orWhere('school_settings.school_name', 'not like', '%gradequest%');
                })
                ->selectRaw("
                    gradequest_invoice_payments.id,
                    gradequest_invoice_payments.amount,
                    COALESCE(gradequest_invoice_payments.paid_at, gradequest_invoice_payments.created_at) as trans_date
                ")
                ->get()
                ->map(function ($r) use ($getTermFromDate) {
                    $date = $r->trans_date ?: now()->toDateTimeString();
                    return [
                        'category' => 'invoices',
                        'amount' => (float) $r->amount,
                        'date' => $date,
                        'year' => (int) date('Y', strtotime($date)),
                        'month_number' => (int) date('n', strtotime($date)),
                        'month' => date('F', strtotime($date)),
                        'month_year' => date('M Y', strtotime($date)),
                        'month_key' => date('Y-m', strtotime($date)),
                        'term' => $getTermFromDate($date),
                    ];
                });
        }

        // 5. WhatsApp Credits (whatsapp_credit_purchases)
        $whatsapp = collect();
        if (Schema::hasTable('whatsapp_credit_purchases')) {
            $whatsapp = DB::table('whatsapp_credit_purchases')
                ->leftJoin('school_settings', 'whatsapp_credit_purchases.school_id', '=', 'school_settings.id')
                ->whereIn('whatsapp_credit_purchases.status', ['success', 'successful', 'paid'])
                ->where(function ($q) {
                    $q->whereNull('whatsapp_credit_purchases.school_id')->orWhere('whatsapp_credit_purchases.school_id', '!=', 68);
                })
                ->where(function ($q) {
                    $q->whereNull('school_settings.school_name')->orWhere('school_settings.school_name', 'not like', '%gradequest%');
                })
                ->selectRaw("
                    whatsapp_credit_purchases.id,
                    whatsapp_credit_purchases.amount,
                    COALESCE(whatsapp_credit_purchases.paid_at, whatsapp_credit_purchases.created_at) as trans_date
                ")
                ->get()
                ->map(function ($r) use ($getTermFromDate) {
                    $date = $r->trans_date ?: now()->toDateTimeString();
                    return [
                        'category' => 'whatsapp_credits',
                        'amount' => (float) $r->amount,
                        'date' => $date,
                        'year' => (int) date('Y', strtotime($date)),
                        'month_number' => (int) date('n', strtotime($date)),
                        'month' => date('F', strtotime($date)),
                        'month_year' => date('M Y', strtotime($date)),
                        'month_key' => date('Y-m', strtotime($date)),
                        'term' => $getTermFromDate($date),
                    ];
                });
        }

        // 6. AI Credits (ai_credit_purchases)
        $aiCredits = collect();
        if (Schema::hasTable('ai_credit_purchases')) {
            $aiCredits = DB::table('ai_credit_purchases')
                ->leftJoin('school_settings', 'ai_credit_purchases.school_id', '=', 'school_settings.id')
                ->whereIn('ai_credit_purchases.status', ['success', 'successful', 'paid'])
                ->where(function ($q) {
                    $q->whereNull('ai_credit_purchases.school_id')->orWhere('ai_credit_purchases.school_id', '!=', 68);
                })
                ->where(function ($q) {
                    $q->whereNull('school_settings.school_name')->orWhere('school_settings.school_name', 'not like', '%gradequest%');
                })
                ->selectRaw("
                    ai_credit_purchases.id,
                    ai_credit_purchases.amount,
                    COALESCE(ai_credit_purchases.paid_at, ai_credit_purchases.created_at) as trans_date
                ")
                ->get()
                ->map(function ($r) use ($getTermFromDate) {
                    $date = $r->trans_date ?: now()->toDateTimeString();
                    return [
                        'category' => 'ai_credits',
                        'amount' => (float) $r->amount,
                        'date' => $date,
                        'year' => (int) date('Y', strtotime($date)),
                        'month_number' => (int) date('n', strtotime($date)),
                        'month' => date('F', strtotime($date)),
                        'month_year' => date('M Y', strtotime($date)),
                        'month_key' => date('Y-m', strtotime($date)),
                        'term' => $getTermFromDate($date),
                    ];
                });
        }

        // 7. Admissions (school_admission_payments)
        $admissions = collect();
        if (Schema::hasTable('school_admission_payments')) {
            $admissions = DB::table('school_admission_payments')
                ->leftJoin('school_settings', 'school_admission_payments.school_id', '=', 'school_settings.id')
                ->whereIn('school_admission_payments.status', ['success', 'successful', 'paid'])
                ->where(function ($q) {
                    $q->whereNull('school_admission_payments.school_id')->orWhere('school_admission_payments.school_id', '!=', 68);
                })
                ->where(function ($q) {
                    $q->whereNull('school_settings.school_name')->orWhere('school_settings.school_name', 'not like', '%gradequest%');
                })
                ->selectRaw("
                    school_admission_payments.id,
                    COALESCE(school_admission_payments.platform_fee, school_admission_payments.total_amount) as amount,
                    COALESCE(school_admission_payments.paid_at, school_admission_payments.created_at) as trans_date
                ")
                ->get()
                ->map(function ($r) use ($getTermFromDate) {
                    $date = $r->trans_date ?: now()->toDateTimeString();
                    return [
                        'category' => 'admissions',
                        'amount' => (float) $r->amount,
                        'date' => $date,
                        'year' => (int) date('Y', strtotime($date)),
                        'month_number' => (int) date('n', strtotime($date)),
                        'month' => date('F', strtotime($date)),
                        'month_year' => date('M Y', strtotime($date)),
                        'month_key' => date('Y-m', strtotime($date)),
                        'term' => $getTermFromDate($date),
                    ];
                });
        }

        $allTransactions = collect()
            ->concat($subscriptions)
            ->concat($domainOrders)
            ->concat($payments)
            ->concat($invoices)
            ->concat($whatsapp)
            ->concat($aiCredits)
            ->concat($admissions);

        $totalPlatformIncome = (float) $allTransactions->sum('amount');
        $totalGmv = (float) $payments->sum('gmv');
        $techFeesEarned = (float) $payments->sum('amount');

        // Current Year & Current Term Income
        $currentYear = now()->year;
        $currentYearIncome = (float) $allTransactions->where('year', $currentYear)->sum('amount');
        $currentTerm = $getTermFromDate(now()->toDateString());
        $currentTermIncome = (float) $allTransactions->where('year', $currentYear)->where('term', $currentTerm)->sum('amount');

        // Yearly Breakdown
        $yearlyBreakdown = $allTransactions
            ->groupBy('year')
            ->map(function ($group, $y) {
                return [
                    'year' => (int) $y,
                    'total' => (float) $group->sum('amount'),
                    'subscriptions' => (float) $group->where('category', 'subscriptions')->sum('amount'),
                    'domains' => (float) $group->where('category', 'domains')->sum('amount'),
                    'tech_fees' => (float) $group->where('category', 'tech_fees')->sum('amount'),
                    'invoices' => (float) $group->where('category', 'invoices')->sum('amount'),
                    'whatsapp_credits' => (float) $group->where('category', 'whatsapp_credits')->sum('amount'),
                    'ai_credits' => (float) $group->where('category', 'ai_credits')->sum('amount'),
                    'admissions' => (float) $group->where('category', 'admissions')->sum('amount'),
                    'transaction_count' => $group->count(),
                ];
            })
            ->sortBy('year')
            ->values();

        // Termly Breakdown
        $termLabels = [
            '1st Term' => 'First Term (September – December)',
            '2nd Term' => 'Second Term (January – April)',
            '3rd Term' => 'Third Term (May – August)',
        ];
        $termlyBreakdown = collect(['1st Term', '2nd Term', '3rd Term'])
            ->map(function ($term) use ($allTransactions, $totalPlatformIncome, $termLabels) {
                $group = $allTransactions->where('term', $term);
                $tot = (float) $group->sum('amount');
                return [
                    'term' => $term,
                    'label' => $termLabels[$term] ?? $term,
                    'total' => $tot,
                    'percentage' => $totalPlatformIncome > 0 ? round(($tot / $totalPlatformIncome) * 100, 1) : 0,
                    'subscriptions' => (float) $group->where('category', 'subscriptions')->sum('amount'),
                    'domains' => (float) $group->where('category', 'domains')->sum('amount'),
                    'tech_fees' => (float) $group->where('category', 'tech_fees')->sum('amount'),
                    'invoices' => (float) $group->where('category', 'invoices')->sum('amount'),
                    'whatsapp_credits' => (float) $group->where('category', 'whatsapp_credits')->sum('amount'),
                    'ai_credits' => (float) $group->where('category', 'ai_credits')->sum('amount'),
                    'admissions' => (float) $group->where('category', 'admissions')->sum('amount'),
                    'transaction_count' => $group->count(),
                ];
            })
            ->values();

        // Category Breakdown
        $categoriesConfig = [
            'subscriptions' => ['name' => 'SaaS Subscriptions & Licenses', 'icon' => 'bi-award', 'color' => '#2563EB'],
            'domains' => ['name' => 'Custom Domain Orders', 'icon' => 'bi-globe2', 'color' => '#059669'],
            'tech_fees' => ['name' => 'Tuition Tech Royalty Fees', 'icon' => 'bi-shield-check', 'color' => '#D97706'],
            'invoices' => ['name' => 'Offline Bank Invoices', 'icon' => 'bi-receipt', 'color' => '#7C3AED'],
            'whatsapp_credits' => ['name' => 'WhatsApp Notification Bundles', 'icon' => 'bi-whatsapp', 'color' => '#16A34A'],
            'ai_credits' => ['name' => 'AI Lesson & Scheme Credits', 'icon' => 'bi-robot', 'color' => '#DB2777'],
            'admissions' => ['name' => 'Online Admissions Processing', 'icon' => 'bi-person-badge', 'color' => '#0D9488'],
        ];

        $categoryBreakdown = collect($categoriesConfig)->map(function ($meta, $key) use ($allTransactions, $totalPlatformIncome) {
            $group = $allTransactions->where('category', $key);
            $tot = (float) $group->sum('amount');
            return [
                'category' => $key,
                'name' => $meta['name'],
                'icon' => $meta['icon'],
                'color' => $meta['color'],
                'total' => $tot,
                'percentage' => $totalPlatformIncome > 0 ? round(($tot / $totalPlatformIncome) * 100, 1) : 0,
                'transaction_count' => $group->count(),
            ];
        })->values();

        // Timeline Data (for the interactive Line Graph!)
        $timelineData = $allTransactions
            ->groupBy('month_key')
            ->map(function ($group, $key) {
                $first = $group->first();
                return [
                    'period' => $first['month_year'],
                    'month' => $first['month'],
                    'year' => $first['year'],
                    'month_key' => $key,
                    'total' => (float) $group->sum('amount'),
                    'subscriptions' => (float) $group->where('category', 'subscriptions')->sum('amount'),
                    'domains' => (float) $group->where('category', 'domains')->sum('amount'),
                    'tech_fees' => (float) $group->where('category', 'tech_fees')->sum('amount'),
                    'invoices' => (float) $group->where('category', 'invoices')->sum('amount'),
                    'whatsapp_credits' => (float) $group->where('category', 'whatsapp_credits')->sum('amount'),
                    'ai_credits' => (float) $group->where('category', 'ai_credits')->sum('amount'),
                    'admissions' => (float) $group->where('category', 'admissions')->sum('amount'),
                ];
            })
            ->sortKeys()
            ->values();

        // Monthly revenue for the current year (backward compatibility with existing monthly bar graph if needed)
        $currentYearMonthly = $allTransactions
            ->where('year', $currentYear)
            ->groupBy('month_number')
            ->map(function ($group, $mNum) {
                $first = $group->first();
                return [
                    'month_number' => $mNum,
                    'month' => $first['month'],
                    'revenue' => (float) $group->sum('amount'),
                ];
            })
            ->sortBy('month_number')
            ->values();

        // Active Students count (Excluding GradeQuest)
        $totalActiveStudents = User::whereRaw('LOWER(role) = ?', ['student'])
            ->where('status', 1)
            ->where(function ($q) {
                $q->whereNull('school_id')
                  ->orWhere('school_id', '!=', 68);
            })
            ->whereDoesntHave('school', function ($q) {
                $q->where('school_name', 'like', '%gradequest%');
            })
            ->count();

        $totalSchools = SchoolSetting::where(function ($q) {
            $q->where('id', '!=', 68)
              ->where('school_name', 'not like', '%gradequest%');
        })->count();

        $splitReadySchools = SchoolBankAccount::where('is_active', true)
            ->where(function ($q) {
                $q->whereNotNull('paystack_subaccount_code')
                  ->orWhereNotNull('monnify_subaccount_code')
                  ->orWhereNotNull('flutterwave_subaccount_code');
            })
            ->where(function ($q) {
                $q->where('school_id', '!=', 68);
            })
            ->distinct('school_id')
            ->count('school_id');

        $onlinePayEnabledSchools = SchoolSetting::where('online_payment_enabled', true)
            ->where('id', '!=', 68)
            ->count();

        return response()->json([
            'status' => 'success',
            'data' => $currentYearMonthly,
            'financial_summary' => [
                'total_platform_income' => $totalPlatformIncome,
                'current_year_income' => $currentYearIncome,
                'current_term_income' => $currentTermIncome,
                'current_term' => $currentTerm,
                'total_gmv' => $totalGmv,
                'tech_fees_earned' => $techFeesEarned,
            ],
            'yearly_breakdown' => $yearlyBreakdown,
            'termly_breakdown' => $termlyBreakdown,
            'category_breakdown' => $categoryBreakdown,
            'timeline_data' => $timelineData,
            'total_active_students' => $totalActiveStudents,
            'total_schools' => $totalSchools,
            'gmv' => $totalGmv,
            'tech_fees_earned' => $techFeesEarned,
            'gateway_split_health' => [
                'total_schools' => $totalSchools,
                'split_ready_schools' => $splitReadySchools,
                'online_payment_enabled_schools' => $onlinePayEnabledSchools,
                'split_health_percentage' => $totalSchools > 0 ? round(($splitReadySchools / $totalSchools) * 100, 1) : 100,
            ],
        ]);
    }

    /**
     * Dedicated SaaS School Onboarding Engine (3-Step Wizard)
     * Step 1: School Identity & Branding
     * Step 2: Financial Gateway Split Configuration (Paystack/Flutterwave/Monnify)
     * Step 3: Lead Administrator Provisioning (Proprietor/Principal)
     */
    public function onboardSchool(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->isSuperAdminUser()) {
            return response()->json(['message' => 'Unauthorized. Super Admin access required.'], 403);
        }

        $validated = $request->validate([
            // Step 1: School Identity & Branding
            'school_name' => 'required|string|max:255',
            'school_code' => 'nullable|string|max:50',
            'prefix' => 'nullable|string|max:20',
            'category' => 'nullable|string|in:nursery,primary,junior_secondary,senior_secondary,all_through',
            'school_subdomain' => 'nullable|string|max:100',
            'custom_domain' => 'nullable|string|max:190',
            'primary_color' => 'nullable|string|max:20',
            'secondary_color' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:190',
            'logo' => 'nullable|image|max:3072',
            'stamp' => 'nullable|image|max:3072',
            'principal_signature' => 'nullable|image|max:3072',

            // Step 2: Financial Gateway Split Configuration
            'bank_name' => 'nullable|string|max:120',
            'bank_code' => 'nullable|string|max:30',
            'account_number' => 'nullable|string|max:30',
            'account_name' => 'nullable|string|max:150',
            'paystack_subaccount_code' => 'nullable|string|max:100',
            'flutterwave_subaccount_code' => 'nullable|string|max:100',
            'monnify_subaccount_code' => 'nullable|string|max:100',
            'tech_royalty_fee' => 'nullable|numeric|min:0|max:50000',
            'platform_fee_bearer' => 'nullable|in:school,parent',
            'bank_charge_bearer' => 'nullable|in:parent,school',
            'bank_charge_amount' => 'nullable|numeric|min:0',
            'active_payment_gateway' => 'nullable|string|in:paystack,monnify,wema_alat',
            'online_payment_enabled' => 'nullable|boolean',
            'active_edition_tier' => 'nullable|string|in:standard_cbt,basic_result,annual_full_session',

            // Step 3: Lead Administrator Provisioning
            'lead_admin_role' => 'nullable|string|in:proprietor,principal,Admin',
            'admin_firstname' => 'required|string|max:100',
            'admin_surname' => 'required|string|max:100',
            'admin_email' => 'required|email|max:190|unique:users,email',
            'admin_phone' => 'required|string|max:30',
            'admin_password' => 'nullable|string|min:8',
        ]);

        DB::beginTransaction();
        try {
            // Auto generate unique 10-digit registration number
            do {
                $regNo = (string) random_int(1000000000, 9999999999);
            } while (User::where('reg_no', $regNo)->exists());

            // Subdomain generation/formatting
            $subdomain = Str::slug($validated['school_subdomain'] ?: $validated['school_name']);
            if (SchoolSetting::where('school_subdomain', $subdomain)->exists()) {
                $subdomain .= '-' . random_int(100, 999);
            }

            // Create school setting
            $schoolData = [
                'school_name' => trim($validated['school_name']),
                'category' => $validated['category'] ?? 'all_through',
                'prefix' => $validated['prefix'] ?? ($validated['school_code'] ?? strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $validated['school_name']), 0, 4))),
                'school_subdomain' => $subdomain,
                'custom_domain' => !empty($validated['custom_domain']) ? strtolower(trim(preg_replace('#^https?://#', '', $validated['custom_domain']))) : null,
                'primary_color' => $validated['primary_color'] ?: '#0F2744',
                'secondary_color' => $validated['secondary_color'] ?: '#D97706',
                'address' => $validated['address'] ?? 'Nigeria',
                'phone' => $validated['phone'] ?? $validated['admin_phone'],
                'email' => $validated['email'] ?? $validated['admin_email'],
                'active_edition_tier' => $validated['active_edition_tier'] ?? 'standard_cbt',
                'active_payment_gateway' => $validated['active_payment_gateway'] ?? 'paystack',
                'online_payment_enabled' => $validated['online_payment_enabled'] ?? true,
                'platform_fee_bearer' => $validated['platform_fee_bearer'] ?? 'school',
                'tech_royalty_fee' => $validated['tech_royalty_fee'] ?? 500.00,
                'bank_charge_bearer' => $validated['bank_charge_bearer'] ?? 'parent',
                'bank_charge_amount' => $validated['bank_charge_amount'] ?? 200.00,
            ];

            // Upload files if provided
            $destPath = public_path('uploads/schools');
            if (!file_exists($destPath)) {
                @mkdir($destPath, 0755, true);
            }

            if ($request->hasFile('logo')) {
                $logoFile = $request->file('logo');
                $logoName = 'logo_' . time() . '_' . Str::slug(pathinfo($logoFile->getClientOriginalName(), PATHINFO_FILENAME)) . '.' . $logoFile->getClientOriginalExtension();
                $logoFile->move($destPath, $logoName);
                $schoolData['logo'] = 'uploads/schools/' . $logoName;
            }

            if ($request->hasFile('stamp')) {
                $stampFile = $request->file('stamp');
                $stampName = 'stamp_' . time() . '_' . Str::slug(pathinfo($stampFile->getClientOriginalName(), PATHINFO_FILENAME)) . '.' . $stampFile->getClientOriginalExtension();
                $stampFile->move($destPath, $stampName);
                $schoolData['stamp'] = 'uploads/schools/' . $stampName;
            }

            if ($request->hasFile('principal_signature')) {
                $sigFile = $request->file('principal_signature');
                $sigName = 'sig_' . time() . '_' . Str::slug(pathinfo($sigFile->getClientOriginalName(), PATHINFO_FILENAME)) . '.' . $sigFile->getClientOriginalExtension();
                $sigFile->move($destPath, $sigName);
                $schoolData['principal_signature'] = 'uploads/schools/' . $sigName;
            }

            $school = SchoolSetting::create($schoolData);

            // Register Custom Domain if provided
            if (!empty($schoolData['custom_domain'])) {
                $cleanDomain = rtrim(strtolower($schoolData['custom_domain']), '/');
                $naked = preg_replace('/^www\./', '', $cleanDomain);
                
                SchoolDomain::firstOrCreate(
                    ['domain' => $naked],
                    ['school_id' => $school->id, 'status' => 'active', 'verification_status' => 'verified']
                );
                SchoolDomain::firstOrCreate(
                    ['domain' => 'www.' . $naked],
                    ['school_id' => $school->id, 'status' => 'active', 'verification_status' => 'verified']
                );
            }

            // Create Subaccount with Paystack if bank info provided and no subaccount code given
            $paystackCode = $validated['paystack_subaccount_code'] ?? null;
            if (!$paystackCode && !empty($validated['bank_code']) && !empty($validated['account_number']) && config('services.paystack.secret')) {
                try {
                    $subRes = Http::withToken(config('services.paystack.secret'))
                        ->post('https://api.paystack.co/subaccount', [
                            'business_name' => $school->school_name,
                            'settlement_bank' => $validated['bank_code'],
                            'account_number' => $validated['account_number'],
                            'percentage_charge' => 0,
                        ]);
                    if ($subRes->successful() && $subRes->json('status')) {
                        $paystackCode = $subRes->json('data.subaccount_code');
                    }
                } catch (\Throwable $subErr) {
                    Log::warning("Paystack auto-subaccount creation on onboard: " . $subErr->getMessage());
                }
            }

            // Provision School Bank Account if account number provided
            $bankAccount = null;
            if (!empty($validated['account_number']) && !empty($validated['bank_name'])) {
                $bankAccount = SchoolBankAccount::create([
                    'school_id' => $school->id,
                    'bank_name' => $validated['bank_name'],
                    'bank_code' => $validated['bank_code'] ?? '000',
                    'account_name' => $validated['account_name'] ?? $school->school_name,
                    'account_number' => $validated['account_number'],
                    'paystack_subaccount_code' => $paystackCode,
                    'flutterwave_subaccount_code' => $validated['flutterwave_subaccount_code'] ?? null,
                    'monnify_subaccount_code' => $validated['monnify_subaccount_code'] ?? null,
                    'preferred_gateway' => $validated['active_payment_gateway'] ?? 'paystack',
                    'online_payment_enabled' => $schoolData['online_payment_enabled'],
                    'currency' => 'NGN',
                    'is_active' => true,
                    'sort_order' => 1,
                ]);
            }

            // Step 3: Provision Lead Administrator
            $plainPassword = $validated['admin_password'] ?: Str::random(10);
            $leadRole = $validated['lead_admin_role'] ?? 'proprietor';
            if ($leadRole === 'Admin') {
                $leadRole = 'proprietor';
            }

            $adminUser = User::create([
                'firstname' => trim($validated['admin_firstname']),
                'surname' => trim($validated['admin_surname']),
                'email' => strtolower(trim($validated['admin_email'])),
                'phone' => trim($validated['admin_phone']),
                'password' => Hash::make($plainPassword),
                'default_password' => $plainPassword,
                'force_password_change' => true,
                'role' => $leadRole,
                'reg_no' => $regNo,
                'school_id' => $school->id,
                'status' => 1,
            ]);

            // Assign role
            $adminUser->assignRole($leadRole);

            // Connect user to school setting
            $school->user_id = $adminUser->id;
            $school->save();

            // Provision default academic structure (classes, terms, sessions)
            try {
                \App\Services\Students\StudentExcelImportService::provisionDefaultAcademicStructure($school->id);
            } catch (\Throwable $pe) {
                Log::warning("Could not provision default academic structure for school {$school->id}: " . $pe->getMessage());
            }

            // Log activity
            try {
                ActivityLog::create([
                    'user_id' => $user->id,
                    'school_id' => $school->id,
                    'action' => 'onboard_school',
                    'description' => "Super Admin onboarded new school: {$school->school_name} (Lead Admin: {$adminUser->email})",
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
            } catch (\Throwable) {}

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => "School '{$school->school_name}' successfully onboarded.",
                'school' => $school->fresh(),
                'lead_admin' => [
                    'id' => $adminUser->id,
                    'name' => $adminUser->name,
                    'email' => $adminUser->email,
                    'phone' => $adminUser->phone,
                    'role' => $adminUser->role,
                    'reg_no' => $adminUser->reg_no,
                    'temporary_password' => $plainPassword,
                ],
                'split_gateway' => [
                    'subaccount_code' => $paystackCode,
                    'tech_royalty_fee' => $school->tech_royalty_fee ?? 500.00,
                    'bank_account' => $bankAccount,
                ],
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('SuperAdmin onboardSchool failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to onboard school: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function deleteMultiple(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:activity_logs,id',
        ]);

        ActivityLog::whereIn('id', $request->ids)->delete();

        return response()->json([
            'message' => 'Selected logs deleted successfully.',
        ]);
    }
}

