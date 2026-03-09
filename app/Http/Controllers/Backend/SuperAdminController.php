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

        // 🔹 Filter by active subscriptions only
        if ($request->has('active') && $request->active == 1) {
            $query->where('ends_at', '>=', now());
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

    $schoolAdmin = User::where('school_id', $user->school_id)
        ->where('role', 'Admin')
        ->first();

    if (!$schoolAdmin) {
        return response()->json(['features' => []]);
    }

    $subscription = $schoolAdmin->activeSubscription()
        ->with('plan')
        ->where('ends_at', '>=', now()) // include current moment
        ->first();

    if (!$subscription || !$subscription->plan) {
        return response()->json(['features' => []]);
    }

    $features = collect(
        is_string($subscription->plan->features)
            ? json_decode($subscription->plan->features, true)
            : $subscription->plan->features
    )->filter(fn($f) => $f['is_enabled'] ?? false)
     ->pluck('feature_key')
     ->map(fn($key) => trim($key)) // remove extra spaces
     ->values();

    return response()->json(['features' => $features]);
}




    
    public function getAdminUsers(Request $request)
    {
        $user = $request->user();
    
        // Only Super-Admin can access
        if (!$user->hasRole('Super-Admin')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
    
        $perPage = $request->get('perPage', 8); 
        $page = $request->get('page', 1);
        $search = $request->input('search', '');
    
        $adminsQuery = User::whereHas('roles', function ($q) {
            $q->where('name', 'Admin');
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

    // Only Super-Admin
    if (!$auth || !$auth->hasRole('Super-Admin')) {
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
    return User::findOrFail($id);
}

public function update(Request $request, $id)
{
    $user = User::findOrFail($id);

    $validated = $request->validate([
        'firstname' => 'required|string',
        'surname' => 'required|string',
        'email' => 'required|email|unique:users,email,' . $user->id,
        'phone' => 'nullable|string',
    ]);

    $user->update($validated);

    return response()->json($user);
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
        if (!$user || !$user->hasRole('Super-Admin')) {
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

            $endsAt = $latestSub?->ends_at ? \Carbon\Carbon::parse($latestSub->ends_at) : null;

            $subState = 'none'; // none | active | expired
            if ($latestSub && $endsAt) {
                $subState = $endsAt->gte(now()) ? 'active' : 'expired';
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

    return response()->json([
        'users' => $users
    ]);
}



    


public function monthlyRevenueStats()
{
    $monthlyRevenue = DB::table('sub_payments')
        ->selectRaw("DATE_FORMAT(created_at, '%M') as month, SUM(amount) as revenue")
        ->whereYear('created_at', now()->year)
        ->where('status', 'successful') // only count successful payments
        ->groupByRaw("MONTH(created_at), DATE_FORMAT(created_at, '%M')")
        ->orderByRaw("MONTH(created_at)")
        ->get()
        ->map(function ($row) {
            return [
                'month' => $row->month,
                'revenue' => (float) $row->revenue,
            ];
        });

    return response()->json([
        'status' => 'success',
        'data' => $monthlyRevenue,
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




