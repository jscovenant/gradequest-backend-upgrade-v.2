<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\GradequestBillingPolicy;
use App\Models\SchoolBillingAuditLog;
use App\Models\SchoolBillingPeriod;
use App\Models\SchoolBillingTemporaryAccess;
use App\Models\SchoolSetting;
use App\Services\SchoolBillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class GradequestBillingPolicyController extends Controller
{
    public function __construct(private SchoolBillingService $billing)
    {
    }

    public function index(Request $request)
    {
        $this->authorizePlatform($request);

        return response()->json([
            'policy' => $this->policy(),
            'temporary_access' => SchoolBillingTemporaryAccess::query()
                ->withoutGlobalScopes()
                ->with('school:id,school_name')
                ->latest()
                ->limit(50)
                ->get(),
            'billing_periods' => $this->billingPeriodsQuery($request)->limit(50)->get(),
        ]);
    }

    public function update(Request $request)
    {
        $this->authorizePlatform($request);

        $validated = $request->validate([
            'online_grace_days' => 'required|integer|min:0|max:90',
            'online_minimum_coverage_percent' => 'required|integer|min:0|max:100',
            'online_whole_school_block_enabled' => 'required|boolean',
            'online_student_level_block_enabled' => 'required|boolean',
            'offline_grace_days' => 'required|integer|min:0|max:90',
            'offline_school_block_enabled' => 'required|boolean',
            'platform_fee_per_student' => 'required|numeric|min:0|max:10000000',
            'whatsapp_credit_unit_price' => 'required|numeric|min:0.01|max:1000000',
            'legacy_plus_ai_credits' => 'required|integer|min:0|max:10000000',
            'ai_result_comment_credit_cost' => 'required|integer|min:1|max:1000000',
            'ai_cbt_question_credit_cost' => 'required|integer|min:1|max:1000000',
            'ai_lesson_plan_credit_cost' => 'required|integer|min:1|max:1000000',
            'ai_scheme_work_credit_cost' => 'required|integer|min:1|max:1000000',
            'ai_lesson_note_credit_cost' => 'required|integer|min:1|max:1000000',
            'ai_fee_collection_credit_cost' => 'required|integer|min:1|max:1000000',
            'ai_credit_unit_price' => 'required|numeric|min:0.01|max:1000000',
            'legacy_subscription_honor_enabled' => 'required|boolean',
            'per_student_billing_starts_at' => 'nullable|date',
            'temporary_access_min_days' => 'required|integer|min:1|max:30',
            'temporary_access_max_days' => 'required|integer|min:1|max:90',
        ]);

        if ($validated['temporary_access_max_days'] < $validated['temporary_access_min_days']) {
            return response()->json(['message' => 'Maximum temporary access days cannot be less than minimum days.'], 422);
        }

        $policy = DB::transaction(function () use ($validated, $request) {
            $policy = $this->policy();
            $before = $policy->toArray();
            $validated['updated_by'] = $request->user()->id;
            $policy->update($validated);

            return $policy->fresh();
        });

        return response()->json([
            'message' => 'Billing policy updated.',
            'policy' => $policy,
        ]);
    }

    public function grantTemporaryAccess(Request $request)
    {
        $this->authorizePlatform($request);

        $policy = $this->policy();

        $validated = $request->validate([
            'school_id' => 'required|exists:school_settings,id',
            'scope' => ['required', Rule::in(['school_crud', 'student_academic', 'all'])],
            'days' => [
                'required',
                'integer',
                'min:' . (int) $policy->temporary_access_min_days,
                'max:' . (int) $policy->temporary_access_max_days,
            ],
            'reason' => 'required|string|max:255',
        ]);

        $access = DB::transaction(function () use ($validated, $request) {
            SchoolBillingTemporaryAccess::where('school_id', $validated['school_id'])
                ->where('scope', $validated['scope'])
                ->where('status', 'active')
                ->where('ends_at', '>', now())
                ->update([
                    'status' => 'revoked',
                    'revoked_at' => now(),
                    'revoked_by' => $request->user()->id,
                ]);

            $access = SchoolBillingTemporaryAccess::create([
                'school_id' => $validated['school_id'],
                'scope' => $validated['scope'],
                'status' => 'active',
                'starts_at' => now(),
                'ends_at' => now()->addDays((int) $validated['days']),
                'granted_by' => $request->user()->id,
                'reason' => $validated['reason'],
            ]);

            SchoolBillingAuditLog::create([
                'school_id' => $validated['school_id'],
                'actor_id' => $request->user()->id,
                'action' => 'temporary_billing_access_granted',
                'auditable_type' => SchoolBillingTemporaryAccess::class,
                'auditable_id' => $access->id,
                'after' => $access->toArray(),
                'reason' => $validated['reason'],
            ]);

            return $access->fresh('school:id,school_name');
        });

        return response()->json([
            'message' => 'Temporary access granted.',
            'temporary_access' => $access,
        ], 201);
    }

    public function revokeTemporaryAccess(Request $request, SchoolBillingTemporaryAccess $temporaryAccess)
    {
        $this->authorizePlatform($request);

        $temporaryAccess->update([
            'status' => 'revoked',
            'revoked_at' => now(),
            'revoked_by' => $request->user()->id,
        ]);

        SchoolBillingAuditLog::create([
            'school_id' => $temporaryAccess->school_id,
            'actor_id' => $request->user()->id,
            'action' => 'temporary_billing_access_revoked',
            'auditable_type' => SchoolBillingTemporaryAccess::class,
            'auditable_id' => $temporaryAccess->id,
            'after' => $temporaryAccess->fresh()->toArray(),
        ]);

        return response()->json([
            'message' => 'Temporary access revoked.',
            'temporary_access' => $temporaryAccess->fresh('school:id,school_name'),
        ]);
    }

    public function schools(Request $request)
    {
        $this->authorizePlatform($request);

        $q = trim((string) $request->query('q', ''));

        $schools = SchoolSetting::query()
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('school_name', 'like', "%{$q}%")
                        ->orWhere('id', $q);
                });
            })
            ->orderBy('school_name')
            ->limit(25)
            ->get(['id', 'school_name']);

        return response()->json(['schools' => $schools]);
    }

    public function billingPeriods(Request $request)
    {
        $this->authorizePlatform($request);

        return response()->json([
            'billing_periods' => $this->billingPeriodsQuery($request)->paginate((int) $request->query('per_page', 25)),
        ]);
    }

    public function syncSchoolCurrentBillingPeriod(Request $request)
    {
        $this->authorizePlatform($request);

        $validated = $request->validate([
            'school_id' => 'required|exists:school_settings,id',
        ]);

        [$session, $term] = $this->billing->currentPeriod((int) $validated['school_id']);

        if (! $session || ! $term) {
            return response()->json(['message' => 'Current academic session or active term is not set for this school.'], 422);
        }

        $period = $this->billing->billingPeriodFor((int) $validated['school_id'], $session, $term, (int) $request->user()->id);

        return response()->json([
            'message' => 'Billing period prepared.',
            'billing_period' => $period->fresh(['school:id,school_name', 'session:id,name', 'term:id,name']),
        ]);
    }

    public function updateBillingPeriod(Request $request, SchoolBillingPeriod $billingPeriod)
    {
        $this->authorizePlatform($request);

        $validated = $request->validate([
            'billing_started_at' => 'required|date',
            'reason' => 'required|string|max:255',
        ]);

        $policy = $this->policy();
        $settings = \App\Models\SchoolBillingSetting::withoutGlobalScopes()
            ->where('school_id', $billingPeriod->school_id)
            ->first();
        $days = ($settings?->payment_mode ?? 'offline') === 'online'
            ? (int) $policy->online_grace_days
            : (int) $policy->offline_grace_days;

        $before = $billingPeriod->toArray();
        $startedAt = \Carbon\Carbon::parse($validated['billing_started_at'])->startOfDay();

        $billingPeriod->update([
            'billing_started_at' => $startedAt,
            'billing_grace_ends_at' => $days > 0 ? $startedAt->copy()->addDays($days)->endOfDay() : null,
            'source' => 'super_admin',
            'locked_at' => now(),
            'locked_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
            'reason' => $validated['reason'],
        ]);

        SchoolBillingAuditLog::create([
            'school_id' => $billingPeriod->school_id,
            'actor_id' => $request->user()->id,
            'action' => 'billing_period_start_updated',
            'auditable_type' => SchoolBillingPeriod::class,
            'auditable_id' => $billingPeriod->id,
            'before' => $before,
            'after' => $billingPeriod->fresh()->toArray(),
            'reason' => $validated['reason'],
        ]);

        return response()->json([
            'message' => 'Billing start date updated.',
            'billing_period' => $billingPeriod->fresh(['school:id,school_name', 'session:id,name', 'term:id,name']),
        ]);
    }

    private function policy(): GradequestBillingPolicy
    {
        return GradequestBillingPolicy::firstOrCreate([], [
            'online_grace_days' => 14,
            'online_minimum_coverage_percent' => 70,
            'online_whole_school_block_enabled' => true,
            'online_student_level_block_enabled' => true,
            'offline_grace_days' => 7,
            'offline_school_block_enabled' => true,
            'platform_fee_per_student' => 1000,
            'whatsapp_credit_unit_price' => 10,
            'legacy_plus_ai_credits' => 100,
            'ai_result_comment_credit_cost' => 1,
            'ai_cbt_question_credit_cost' => 5,
            'ai_lesson_plan_credit_cost' => 3,
            'ai_scheme_work_credit_cost' => 4,
            'ai_lesson_note_credit_cost' => 5,
            'ai_fee_collection_credit_cost' => 2,
            'ai_credit_unit_price' => 25,
            'legacy_subscription_honor_enabled' => true,
            'per_student_billing_starts_at' => now(),
            'temporary_access_min_days' => 3,
            'temporary_access_max_days' => 7,
        ]);
    }

    private function billingPeriodsQuery(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        return SchoolBillingPeriod::withoutGlobalScopes()
            ->with(['school:id,school_name', 'session:id,name', 'term:id,name'])
            ->when($q !== '', function ($query) use ($q) {
                $query->whereHas('school', fn ($school) => $school->where('school_name', 'like', "%{$q}%"))
                    ->orWhere('school_id', $q);
            })
            ->orderByRaw('CASE WHEN flagged_at IS NULL THEN 1 ELSE 0 END ASC')
            ->orderByDesc('updated_at');
    }

    private function authorizePlatform(Request $request): void
    {
        $user = $request->user();

        abort_unless($user && $user->isSuperAdminUser(), 403);
    }
}


