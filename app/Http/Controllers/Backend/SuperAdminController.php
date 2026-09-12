<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Mail\MarketingEmail;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use App\Models\School;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\Average;
use App\Models\SchoolSetting;
use App\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use \App\Models\SubscriptionPlan;



class SuperAdminController extends Controller
{
  
   
    public function getSubscribers(Request $request)
    {
        $query = Subscription::with(['user', 'plan']);

        // 🔹 Filter by status (optional)
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // 🔹 Filter by active subscriptions only (including lifetime / null ends_at)
        if ($request->has('active') && $request->active == 1) {
            $query->where(function ($q) {
                $q->whereNull('ends_at')
                  ->orWhere('ends_at', '>=', now());
            });
        }

        // 🔹 Search by user name or email
        if ($request->has('search') && $request->search != '') {
            $search = $request->search;
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // 🔹 Pagination (default 10 per page)
        $subscriptions = $query->orderBy('starts_at', 'desc')->paginate($request->get('per_page', 10));

        return response()->json([
            'success' => true,
            'data' => $subscriptions
        ]);
    }


public function getUserFeatures(Request $request)
{
    $user = $request->user();
    $schoolId = (int) ($user?->school_id ?? 0);
    $schoolSetting = $schoolId ? \App\Models\SchoolSetting::find($schoolId) : null;
    $tier = $schoolSetting?->active_edition_tier ?: 'standard_cbt';

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
        'ai_cbt_question_generator',
        'ai_result_comment_generator',
        'gradequest_plus',
        'whatsapp_notifications',
        'whatsapp_messaging',
        'hostel_management',
        'transport_management',
        'fees',
        'results',
    ];

    // Only include CBT features if the school is on the Full CBT & AI Edition or Annual Full Session Tier
    if ($tier !== 'basic_result') {
        $allFeatures[] = 'cbt_online';
        $allFeatures[] = 'cbt_offline';
        $allFeatures[] = 'cbt';
    }

    return response()->json([
        'features' => $allFeatures,
        'active_edition_tier' => $tier,
    ]);
}




    
    public function getAdminUsers(Request $request)
    {
        $user = $request->user();
    
        if (!$user || !$user->isSuperAdminUser()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
    
        $perPage = $request->get('perPage', 8); 
        $page = $request->get('page', 1);
        $search = $request->input('search', '');
    
        $adminsQuery = User::whereHas('roles', function ($q) {
            $q->where('name', 'Admin');
        })
        ->where(function ($q) {
            $q->whereNull('school_id')
              ->orWhere('school_id', '!=', 68);
        })
        ->where('email', '!=', 'gradequestapp@gmail.com')
        ->whereDoesntHave('school', function ($q) {
            $q->where('school_name', 'like', '%gradequest international%');
        })
        ->when($search, function ($q) use ($search) {
            $q->where(function ($query) use ($search) {
                $query->where('firstname', 'like', "%$search%")
                      ->orWhere('surname', 'like', "%$search%")
                      ->orWhere('email', 'like', "%$search%");
            });
        })
        ->with(['roles', 'school'])
        ->orderBy('created_at', 'desc');
    
        $admins = $adminsQuery->paginate($perPage, ['*'], 'page', $page);
    
        return response()->json($admins);
    }
    



public function showAdmin($id)
{
    $auth = request()->user();

    if (!$auth || !$auth->isSuperAdminUser()) {
        return response()->json(['message' => 'Unauthorized'], 403);
    }

    $admin = User::with('school')->findOrFail($id);

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
    ]);

    $admin->update([
        'firstname' => $validated['firstname'],
        'surname' => $validated['surname'],
        'email' => $validated['email'],
        'phone' => $validated['phone'] ?? $admin->phone,
        'address' => $validated['address'] ?? $admin->address,
    ]);

    if (!empty($validated['school_name']) && $admin->school) {
        $admin->school->update([
            'school_name' => $validated['school_name'],
        ]);
    }

    return response()->json([
        'status' => 'success',
        'message' => 'Admin profile updated successfully.',
        'admin' => $admin->fresh(['school']),
    ]);
}

public function toggleAdminStatus(Request $request, $id)
{
    $auth = $request->user();
    if (!$auth || !$auth->isSuperAdminUser()) {
        return response()->json(['message' => 'Unauthorized'], 403);
    }

    $admin = User::with('school')->findOrFail($id);
    $newStatus = $request->has('status') ? (int) $request->input('status') : ((int) $admin->status === 1 ? 0 : 1);

    $admin->status = $newStatus;
    $admin->save();

    if ($newStatus === 0) {
        // Immediately revoke all active sessions for this admin and their staff/students
        $admin->tokens()->delete();
        if ($admin->school_id) {
            User::where('school_id', $admin->school_id)->each(function ($u) {
                $u->tokens()->delete();
            });
        }
    }

    return response()->json([
        'status' => 'success',
        'message' => $newStatus === 1 ? "Admin account for {$admin->email} has been reinstated and activated." : "Admin account for {$admin->email} has been suspended.",
        'admin' => $admin,
        'new_status' => $newStatus,
    ]);
}

public function resetAdminPassword(Request $request, $id)
{
    $auth = $request->user();
    if (!$auth || !$auth->isSuperAdminUser()) {
        return response()->json(['message' => 'Unauthorized'], 403);
    }

    $admin = User::findOrFail($id);
    $newPassword = $request->input('password') ?: Str::random(10);

    $admin->password = Hash::make($newPassword);
    $admin->default_password = $newPassword;
    $admin->force_password_change = true;
    $admin->save();

    // Revoke previous tokens
    $admin->tokens()->delete();

    return response()->json([
        'status' => 'success',
        'message' => "Password reset successfully for {$admin->email}.",
        'temporary_password' => $newPassword,
    ]);
}



public function destroy($id)
{
    $admin = User::find($id);

    if (!$admin) {
        return response()->json(['message' => 'User not found.'], 404);
    }

    // Optional: Prevent self-deletion
    if (Auth::id() == $admin->id) {
        return response()->json(['message' => 'You cannot delete yourself.'], 403);
    }

    DB::beginTransaction();

    try {
        // If the user has a school, delete it
        if ($admin->school_id) {
            $schoolSetting = \App\Models\SchoolSetting::where('id', $admin->school_id)->first();

            if ($schoolSetting) {
                $schoolSetting->delete();
            }

            // Optional: Delete the school record too if it exists
            SchoolSetting::where('id', $admin->school_id)->delete();
        }

        $admin->delete();

        DB::commit();

        return response()->json(['message' => 'User and associated school deleted successfully.']);
    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json(['message' => 'Failed to delete user.'], 500);
    }
}








public function getLogs(Request $request)
{
    $perPage = $request->get('per_page', 10); // default to 10
    $logs = ActivityLog::with('user')
        ->orderBy('created_at', 'desc')
        ->paginate($perPage);

    // Format the logs
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
    // Classification:
    // free            -> no subscription OR plan is "Free"
    // premium_active  -> latest plan != "Free" AND ends_at >= now
    // premium_expired -> latest plan != "Free" AND ends_at < now
    //
    // Premium = premium_active + premium_expired

    $users = User::where('role', 'Admin')
        ->where(function ($q) {
            $q->whereNull('school_id')
              ->orWhere('school_id', '!=', 68);
        })
        ->where('email', '!=', 'gradequestapp@gmail.com')
        ->whereDoesntHave('school', function ($q) {
            $q->where('school_name', 'like', '%gradequest international%');
        })
        ->with([
            'subscriptions' => function ($q) {
                $q->with('plan:id,name,price,duration_in_days')
                  ->orderByDesc('created_at');
            },
            'school' // optional (if you want school info here too)
        ])
        ->get(['id', 'firstname', 'surname', 'email', 'status', 'school_id'])
        ->map(function ($u) {

            $latestSub = $u->subscriptions->first(); // latest subscription record (may be null)
            $planName  = $latestSub?->plan?->name;

            // Treat missing plan or "Free" as free
            $isFreePlan = !$planName || strtolower(trim($planName)) === 'free';

            $subState = 'none'; // none | active | expired
            if ($latestSub) {
                if (!$latestSub->ends_at) {
                    $subState = 'active'; // Perpetual / Lifetime subscription
                } else {
                    $endsAt = \Carbon\Carbon::parse($latestSub->ends_at);
                    $subState = $endsAt->gte(now()) ? 'active' : 'expired';
                }
            }

            $tier = 'free';
            if (!$isFreePlan && $latestSub) {
                $tier = ($subState === 'active') ? 'premium_active' : 'premium_expired';
            }

            return [
                'id' => $u->id,
                'firstname' => $u->firstname,
                'surname' => $u->surname,
                'email' => $u->email,
                'status' => $u->status,

                // Subscription classification
                'tier' => $tier, // free | premium_active | premium_expired
                'plan_name' => $planName ?? 'Free',
                'subscription_status' => $latestSub?->status ?? null,
                'subscription_starts_at' => $latestSub?->starts_at,
                'subscription_ends_at' => $latestSub?->ends_at,

                // optional school info if needed
                'school' => $u->school ? [
                    'id' => $u->school->id ?? null,
                    'school_name' => $u->school->school_name ?? null,
                    'email' => $u->school->email ?? null,
                    'phone' => $u->school->phone ?? null,
                    'address' => $u->school->address ?? null,
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

    $combined = $users->concat($newsletterSubscribers);

    return response()->json([
        'users' => $combined
    ]);
}



    


public function monthlyRevenueStats()
{
    $year = now()->year;

    $subscriptionRevenue = DB::table('sub_payments')
        ->selectRaw("MONTH(COALESCE(paid_at, created_at)) as month_number, DATE_FORMAT(COALESCE(paid_at, created_at), '%M') as month, SUM(amount) as revenue")
        ->whereYear(DB::raw('COALESCE(paid_at, created_at)'), $year)
        ->where('status', 'successful')
        ->groupByRaw("MONTH(COALESCE(paid_at, created_at)), DATE_FORMAT(COALESCE(paid_at, created_at), '%M')")
        ->get();

    $onlinePlatformRevenue = DB::table('payments')
        ->selectRaw("MONTH(created_at) as month_number, DATE_FORMAT(created_at, '%M') as month, SUM(platform_fee) as revenue")
        ->whereYear('created_at', $year)
        ->where('status', 'success')
        ->where('platform_fee', '>', 0)
        ->groupByRaw("MONTH(created_at), DATE_FORMAT(created_at, '%M')")
        ->get();

    $offlineInvoiceRevenue = DB::table('gradequest_invoice_payments')
        ->selectRaw("MONTH(COALESCE(paid_at, created_at)) as month_number, DATE_FORMAT(COALESCE(paid_at, created_at), '%M') as month, SUM(amount) as revenue")
        ->whereYear(DB::raw('COALESCE(paid_at, created_at)'), $year)
        ->whereIn('status', ['success', 'successful', 'paid'])
        ->groupByRaw("MONTH(COALESCE(paid_at, created_at)), DATE_FORMAT(COALESCE(paid_at, created_at), '%M')")
        ->get();

    $monthlyRevenue = collect()
        ->merge($subscriptionRevenue)
        ->merge($onlinePlatformRevenue)
        ->merge($offlineInvoiceRevenue)
        ->groupBy('month_number')
        ->sortKeys()
        ->map(function ($rows) {
            $first = $rows->first();

            return [
                'month' => $first->month,
                'revenue' => (float) $rows->sum(fn ($row) => (float) $row->revenue),
            ];
        })
        ->values();

    $totalActiveStudents = User::whereRaw('LOWER(role) = ?', ['student'])
        ->where('status', 1)
        ->where(function ($q) {
            $q->whereNull('school_id')
              ->orWhere('school_id', '!=', 68);
        })
        ->whereDoesntHave('school', function ($q) {
            $q->where('school_name', 'like', '%gradequest international%');
        })
        ->count();

    return response()->json([
        'status' => 'success',
        'data' => $monthlyRevenue,
        'total_active_students' => $totalActiveStudents,
    ]);
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

