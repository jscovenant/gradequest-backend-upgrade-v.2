<?php
namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Mail\SalesRepresentativeLoginMail;
use App\Mail\SchoolAdminLoginMail;
use App\Models\SalesCommission;
use App\Models\SalesRepAssignment;
use App\Models\SalesRepStatusEvent;
use App\Models\SalesRepresentative;
use App\Models\SchoolSetting;
use App\Models\User;
use App\Services\SalesRepresentativeActivityService;
use App\Services\SalesReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SalesRepresentativeController extends Controller
{
    public function __construct(
        private SalesRepresentativeActivityService $activityService,
        private SalesReferralService $referrals,
    )
    {
    }

    public function workspace(Request $request): JsonResponse
    {
        $rep = $this->currentRepresentative($request)
            ->load([
                'user:id,firstname,surname,email,phone,role,status',
                'assignments.demoBooking',
                'assignments.school',
                'assignments.adminUser:id,firstname,surname,email,phone,school_id',
                'commissions.school',
            ]);

        $assignments = $rep->assignments;
        $commissions = $rep->commissions;
        $recentLeads = $assignments->sortByDesc('updated_at')->take(6)->values();

        return response()->json([
            'representative' => $this->representativePayload($rep, true),
            'summary' => [
                'assigned_leads' => $assignments->count(),
                'converted_leads' => $assignments->where('stage', 'converted')->count(),
                'open_leads' => $assignments->whereNotIn('stage', ['converted', 'lost'])->count(),
                'pipeline_value' => (float) $assignments->sum('pipeline_value'),
                'pending_commission' => (float) $commissions->where('status', 'pending')->sum('amount'),
                'approved_commission' => (float) $commissions->where('status', 'approved')->sum('amount'),
                'paid_commission' => (float) $commissions->where('status', 'paid')->sum('amount'),
                'monthly_target_amount' => (float) $rep->monthly_target_amount,
                'monthly_target_schools' => (int) $rep->monthly_target_schools,
                'sales_page_views' => $rep->pageEvents()->where('event_type', 'page_view')->count(),
                'sales_page_leads' => $rep->pageEvents()->where('event_type', 'lead_submitted')->count(),
            ],
            'recent_leads' => $recentLeads,
            'commissions' => $commissions->sortByDesc('created_at')->take(6)->values(),
        ]);
    }

    public function myLeads(Request $request): JsonResponse
    {
        $rep = $this->currentRepresentative($request);
        $stage = trim((string) $request->get('stage', ''));
        $search = trim((string) $request->get('search', ''));
        $perPage = min(max((int) $request->get('per_page', 20), 1), 100);

        $query = $rep->assignments()
            ->with(['demoBooking', 'school', 'adminUser:id,firstname,surname,email,phone,school_id'])
            ->latest();

        if ($stage !== '') {
            $query->where('stage', $stage);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('notes', 'like', "%{$search}%")
                    ->orWhere('source', 'like', "%{$search}%")
                    ->orWhereHas('demoBooking', fn ($booking) => $booking->where('school_name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
                    ->orWhereHas('school', fn ($school) => $school->where('school_name', 'like', "%{$search}%"))
                    ->orWhereHas('adminUser', fn ($admin) => $admin->where('firstname', 'like', "%{$search}%")->orWhere('surname', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
            });
        }

        return response()->json([
            'representative' => $this->representativePayload($rep->load(['user', 'assignments', 'commissions'])),
            'leads' => $query->paginate($perPage),
        ]);
    }

    public function storeMyLead(Request $request): JsonResponse
    {
        $rep = $this->currentRepresentative($request);

        $data = $request->validate([
            'prospect_school_name' => ['required', 'string', 'max:180'],
            'contact_name' => ['nullable', 'string', 'max:160'],
            'contact_email' => ['nullable', 'email', 'max:180'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'location' => ['nullable', 'string', 'max:180'],
            'expected_students' => ['nullable', 'integer', 'min:0'],
            'stage' => ['nullable', Rule::in(['lead', 'contacted', 'demo_booked', 'proposal_sent', 'follow_up', 'converted', 'lost'])],
            'pipeline_value' => ['nullable', 'numeric', 'min:0'],
            'expected_close_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $lead = SalesRepAssignment::create([
            'sales_representative_id' => $rep->id,
            'prospect_school_name' => $data['prospect_school_name'],
            'contact_name' => $data['contact_name'] ?? null,
            'contact_email' => $data['contact_email'] ?? null,
            'contact_phone' => $data['contact_phone'] ?? null,
            'location' => $data['location'] ?? null,
            'expected_students' => $data['expected_students'] ?? null,
            'stage' => $data['stage'] ?? 'lead',
            'source' => 'sales_rep',
            'pipeline_value' => $data['pipeline_value'] ?? 0,
            'expected_close_date' => $data['expected_close_date'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json([
            'message' => 'Lead registered successfully.',
            'data' => $lead->fresh(['demoBooking', 'school', 'adminUser']),
        ], 201);
    }

    public function myCommissions(Request $request): JsonResponse
    {
        $rep = $this->currentRepresentative($request);
        $status = trim((string) $request->get('status', ''));
        $perPage = min(max((int) $request->get('per_page', 20), 1), 100);

        $query = $rep->commissions()
            ->with(['school', 'subscription.plan', 'subPayment'])
            ->latest();

        if ($status !== '') {
            $query->where('status', $status);
        }

        return response()->json([
            'representative' => $this->representativePayload($rep->load(['user', 'assignments', 'commissions'])),
            'summary' => [
                'pending' => (float) $rep->commissions()->where('status', 'pending')->sum('amount'),
                'approved' => (float) $rep->commissions()->where('status', 'approved')->sum('amount'),
                'paid' => (float) $rep->commissions()->where('status', 'paid')->sum('amount'),
            ],
            'commissions' => $query->paginate($perPage),
        ]);
    }

    public function allLeads(Request $request): JsonResponse
    {
        $stage = trim((string) $request->get('stage', ''));
        $search = trim((string) $request->get('search', ''));
        $representativeId = (int) $request->get('sales_representative_id', 0);
        $perPage = min(max((int) $request->get('per_page', 20), 1), 100);

        $query = SalesRepAssignment::query()
            ->with([
                'representative.user:id,firstname,surname,email,phone,role,status',
                'demoBooking',
                'school',
                'adminUser:id,firstname,surname,email,phone,school_id,reg_no',
            ])
            ->latest();

        if ($stage !== '') {
            $query->where('stage', $stage);
        }

        if ($representativeId > 0) {
            $query->where('sales_representative_id', $representativeId);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('prospect_school_name', 'like', "%{$search}%")
                    ->orWhere('contact_name', 'like', "%{$search}%")
                    ->orWhere('contact_email', 'like', "%{$search}%")
                    ->orWhere('contact_phone', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%")
                    ->orWhereHas('representative.user', fn ($user) => $user
                        ->where('firstname', 'like', "%{$search}%")
                        ->orWhere('surname', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"))
                    ->orWhereHas('demoBooking', fn ($booking) => $booking
                        ->where('school_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"))
                    ->orWhereHas('school', fn ($school) => $school->where('school_name', 'like', "%{$search}%"));
            });
        }

        return response()->json([
            'summary' => [
                'total' => SalesRepAssignment::count(),
                'open' => SalesRepAssignment::whereNotIn('stage', ['converted', 'lost'])->count(),
                'converted' => SalesRepAssignment::where('stage', 'converted')->count(),
                'lost' => SalesRepAssignment::where('stage', 'lost')->count(),
                'pipeline_value' => (float) SalesRepAssignment::whereNotIn('stage', ['converted', 'lost'])->sum('pipeline_value'),
            ],
            'leads' => $query->paginate($perPage),
        ]);
    }

    public function updateLeadStage(Request $request, SalesRepAssignment $lead): JsonResponse
    {
        $data = $request->validate([
            'stage' => ['required', Rule::in(['lead', 'contacted', 'demo_booked', 'proposal_sent', 'follow_up', 'converted', 'lost'])],
            'notes' => ['nullable', 'string'],
        ]);

        if ($data['stage'] === 'converted' && (! $lead->school_id || ! $lead->admin_user_id)) {
            return response()->json([
                'message' => 'Convert this lead to a school account before marking it as converted.',
            ], 422);
        }

        $lead->update([
            'stage' => $data['stage'],
            'notes' => $data['notes'] ?? $lead->notes,
            'converted_at' => $data['stage'] === 'converted' ? ($lead->converted_at ?: now()) : $lead->converted_at,
        ]);

        return response()->json([
            'message' => 'Lead stage updated successfully.',
            'data' => $lead->fresh(['representative.user', 'demoBooking', 'school', 'adminUser']),
        ]);
    }

    public function convertLeadToSchool(Request $request, SalesRepAssignment $lead): JsonResponse
    {
        if ($lead->school_id || $lead->admin_user_id) {
            return response()->json([
                'message' => 'This lead has already been linked to a school account.',
            ], 422);
        }

        $data = $request->validate([
            'school_name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'admin_firstname' => ['required', 'string', 'max:120'],
            'admin_surname' => ['nullable', 'string', 'max:120'],
            'admin_email' => ['required', 'email', 'max:180', 'unique:users,email'],
            'admin_phone' => ['nullable', 'string', 'max:40'],
            'password' => ['nullable', 'string', 'min:8'],
            'send_login_email' => ['nullable', 'boolean'],
        ]);

        $plainPassword = $data['password'] ?? Str::password(10);
        $school = null;
        $admin = null;

        DB::transaction(function () use ($data, $lead, $plainPassword, &$school, &$admin) {
            $school = SchoolSetting::create([
                'school_name' => $data['school_name'],
                'email' => $data['admin_email'],
                'phone' => $data['admin_phone'] ?? $lead->contact_phone,
                'address' => $data['address'] ?? $lead->location,
            ]);

            $admin = User::create([
                'firstname' => $data['admin_firstname'],
                'surname' => $data['admin_surname'] ?? null,
                'reg_no' => $this->generateAdminRegNo(),
                'email' => $data['admin_email'],
                'username' => $data['admin_email'],
                'role' => 'Admin',
                'phone' => $data['admin_phone'] ?? $lead->contact_phone,
                'password' => Hash::make($plainPassword),
                'default_password' => $plainPassword,
                'force_password_change' => true,
                'school_id' => $school->id,
                'status' => 1,
            ]);

            try {
                $admin->assignRole('Admin');
            } catch (\Throwable $e) {
                Log::warning('Sales conversion admin role assignment failed', [
                    'user_id' => $admin->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $school->update(['user_id' => $admin->id]);

            $lead->update([
                'school_id' => $school->id,
                'admin_user_id' => $admin->id,
                'stage' => 'converted',
                'converted_at' => now(),
                'notes' => trim(($lead->notes ? $lead->notes . "\n\n" : '') . 'Converted to school account by Super Admin on ' . now()->toDateTimeString()),
            ]);
        });

        $emailSent = false;
        $emailError = null;

        if (($data['send_login_email'] ?? true) && $admin && $school) {
            [$emailSent, $emailError] = $this->sendSchoolAdminLoginMail($admin, $school, $plainPassword);
        }

        return response()->json([
            'message' => $emailSent
                ? 'Lead converted and login details emailed. The school admin can claim the ₦5,000 GradeQuestPlus wallet credit after completing onboarding.'
                : 'Lead converted. The school admin can claim the ₦5,000 GradeQuestPlus wallet credit after completing onboarding.',
            'data' => $lead->fresh(['representative.user', 'demoBooking', 'school', 'adminUser']),
            'school' => $school,
            'admin' => $admin,
            'login_details' => [
                'login_url' => $this->loginUrl(),
                'email' => $admin?->email,
                'reg_no' => $admin?->reg_no,
                'temporary_password' => $plainPassword,
                'must_change_password' => true,
                'welcome_wallet_credit' => 'Claimable after onboarding activation',
                'email_sent' => $emailSent,
                'email_error' => $emailError,
            ],
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->get('search', ''));
        $status = trim((string) $request->get('status', ''));
        $perPage = min(max((int) $request->get('per_page', 15), 1), 100);

        $query = SalesRepresentative::query()
            // Do not explicitly select last_login_at here. Older databases may
            // not have run the activity-tracking migration yet, and selecting a
            // missing column makes the entire representatives page fail.
            ->with(['user', 'assignments', 'commissions'])
            ->latest();

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('region', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('firstname', 'like', "%{$search}%")
                            ->orWhere('surname', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    });
            });
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        $representatives = $query->paginate($perPage);

        $representatives->getCollection()->transform(fn (SalesRepresentative $rep) => $this->representativePayload($rep));

        return response()->json([
            'summary' => $this->summaryPayload(),
            'representatives' => $representatives,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'firstname' => ['required', 'string', 'max:120'],
            'surname' => ['nullable', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:40'],
            'region' => ['nullable', 'string', 'max:120'],
            'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'core_commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'premium_commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'monthly_target_amount' => ['nullable', 'numeric', 'min:0'],
            'monthly_target_schools' => ['nullable', 'integer', 'min:0'],
            'next_of_kin_name' => ['nullable', 'string', 'max:180'],
            'next_of_kin_phone' => ['nullable', 'string', 'max:50'],
            'next_of_kin_relationship' => ['nullable', 'string', 'max:80'],
            'joined_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'password' => ['nullable', 'string', 'min:8'],
            'send_login_email' => ['nullable', 'boolean'],
        ]);

        $plainPassword = null;

        $rep = DB::transaction(function () use ($data, &$plainPassword) {
            $code = $this->generateCode();
            $plainPassword = $data['password'] ?? Str::password(10);

            $user = User::create([
                'firstname' => $data['firstname'],
                'surname' => $data['surname'] ?? null,
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'username' => $code,
                'reg_no' => $code,
                'role' => 'Sales-Representative',
                'status' => 1,
                'password' => Hash::make($plainPassword),
                'default_password' => $plainPassword,
                'force_password_change' => true,
            ]);

            return SalesRepresentative::create([
                'user_id' => $user->id,
                'code' => $code,
                'region' => $data['region'] ?? null,
                'status' => 'active',
                'commission_rate' => $data['premium_commission_rate'] ?? $data['commission_rate'] ?? 5,
                'core_commission_rate' => $data['core_commission_rate'] ?? $data['commission_rate'] ?? 5,
                'premium_commission_rate' => $data['premium_commission_rate'] ?? $data['commission_rate'] ?? 5,
                'monthly_target_amount' => $data['monthly_target_amount'] ?? 0,
                'monthly_target_schools' => $data['monthly_target_schools'] ?? 0,
                'next_of_kin_name' => $data['next_of_kin_name'] ?? null,
                'next_of_kin_phone' => $data['next_of_kin_phone'] ?? null,
                'next_of_kin_relationship' => $data['next_of_kin_relationship'] ?? null,
                'joined_at' => $data['joined_at'] ?? now()->toDateString(),
                'notes' => $data['notes'] ?? null,
            ])->load(['user', 'assignments', 'commissions']);
        });

        $emailSent = false;
        $emailError = null;

        if (($data['send_login_email'] ?? true) && $plainPassword) {
            [$emailSent, $emailError] = $this->sendLoginMail($rep, $plainPassword);
        }

        return response()->json([
            'message' => $emailSent
                ? 'Sales representative created and login details emailed successfully.'
                : 'Sales representative created successfully.',
            'data' => $this->representativePayload($rep),
            'login_details' => [
                'login_url' => $this->loginUrl(),
                'email' => $rep->user?->email,
                'sales_code' => $rep->code,
                'temporary_password' => $plainPassword,
                'must_change_password' => true,
                'email_sent' => $emailSent,
                'email_error' => $emailError,
            ],
        ], 201);
    }

    public function sendLoginDetails(SalesRepresentative $salesRepresentative): JsonResponse
    {
        $salesRepresentative->load('user');
        $password = (string) ($salesRepresentative->user?->default_password ?? '');

        if ($password === '') {
            return response()->json([
                'message' => 'No temporary password is available for this representative. Please reset the password first.',
            ], 422);
        }

        [$sent, $error] = $this->sendLoginMail($salesRepresentative, $password);

        if (! $sent) {
            return response()->json([
                'message' => 'Login details could not be emailed.',
                'error' => $error,
            ], 500);
        }

        return response()->json([
            'message' => 'Login details emailed successfully.',
            'login_details' => [
                'login_url' => $this->loginUrl(),
                'email' => $salesRepresentative->user?->email,
                'sales_code' => $salesRepresentative->code,
            ],
        ]);
    }

    public function show(SalesRepresentative $salesRepresentative): JsonResponse
    {
        $salesRepresentative->load([
            'user:id,firstname,surname,email,phone,role,status',
            'assignments.demoBooking',
            'assignments.school',
            'assignments.adminUser:id,firstname,surname,email,phone,school_id',
            'commissions.school',
            'commissions.subscription',
            'commissions.subPayment',
        ]);

        return response()->json([
            'data' => $this->representativePayload($salesRepresentative, true),
        ]);
    }

    public function update(Request $request, SalesRepresentative $salesRepresentative): JsonResponse
    {
        $data = $request->validate([
            'firstname' => ['sometimes', 'required', 'string', 'max:120'],
            'surname' => ['nullable', 'string', 'max:120'],
            'email' => ['sometimes', 'required', 'email', 'max:180', Rule::unique('users', 'email')->ignore($salesRepresentative->user_id)],
            'phone' => ['nullable', 'string', 'max:40'],
            'region' => ['nullable', 'string', 'max:120'],
            'status' => ['sometimes', 'required', Rule::in(['active', 'suspended', 'under_review', 'terminated', 'closed', 'deceased', 'inactive', 'paused'])],
            'status_reason' => ['nullable', 'string'],
            'closure_requested_at' => ['nullable', 'date'],
            'death_reported_at' => ['nullable', 'date'],
            'next_of_kin_name' => ['nullable', 'string', 'max:180'],
            'next_of_kin_phone' => ['nullable', 'string', 'max:50'],
            'next_of_kin_relationship' => ['nullable', 'string', 'max:80'],
            'final_settlement_status' => ['nullable', Rule::in(['pending_review', 'approved', 'paid', 'forfeited', 'not_applicable'])],
            'final_settlement_notes' => ['nullable', 'string'],
            'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'core_commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'premium_commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'monthly_target_amount' => ['nullable', 'numeric', 'min:0'],
            'monthly_target_schools' => ['nullable', 'integer', 'min:0'],
            'joined_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        if (array_key_exists('premium_commission_rate', $data)) {
            $data['commission_rate'] = $data['premium_commission_rate'];
        }

        DB::transaction(function () use ($data, $salesRepresentative, $request) {
            $userData = array_intersect_key($data, array_flip(['firstname', 'surname', 'email', 'phone']));

            if ($userData) {
                $salesRepresentative->user()->update($userData);
            }

            $oldStatus = $salesRepresentative->status;
            $salesRepresentative->update(array_intersect_key($data, array_flip([
                'region',
                'status',
                'status_reason',
                'closure_requested_at',
                'death_reported_at',
                'next_of_kin_name',
                'next_of_kin_phone',
                'next_of_kin_relationship',
                'final_settlement_status',
                'final_settlement_notes',
                'commission_rate',
                'core_commission_rate',
                'premium_commission_rate',
                'monthly_target_amount',
                'monthly_target_schools',
                'joined_at',
                'notes',
            ])));

            if (array_key_exists('status', $data) && $oldStatus !== $data['status']) {
                $salesRepresentative->update(['status_changed_at' => now()]);
                $salesRepresentative->user()->update([
                    'status' => $data['status'] === 'active' ? 1 : 0,
                ]);

                SalesRepStatusEvent::create([
                    'sales_representative_id' => $salesRepresentative->id,
                    'changed_by' => $request->user()?->id,
                    'old_status' => $oldStatus,
                    'new_status' => $data['status'],
                    'reason' => $data['status_reason'] ?? null,
                    'metadata' => [
                        'final_settlement_status' => $data['final_settlement_status'] ?? null,
                    ],
                ]);

                if (in_array($data['status'], ['suspended', 'under_review', 'terminated', 'closed', 'deceased'], true)) {
                    SalesCommission::query()
                        ->where('sales_representative_id', $salesRepresentative->id)
                        ->whereIn('status', ['pending', 'approved'])
                        ->whereDoesntHave('payoutItem')
                        ->update([
                            'status' => 'held',
                            'hold_reason' => 'Sales representative account status changed to ' . $data['status'] . '.',
                            'reviewed_at' => now(),
                            'updated_at' => now(),
                        ]);
                }
            }
        });

        $salesRepresentative->refresh()->load(['user', 'assignments', 'commissions']);

        return response()->json([
            'message' => 'Sales representative updated successfully.',
            'data' => $this->representativePayload($salesRepresentative),
        ]);
    }

    public function reactivate(Request $request, SalesRepresentative $salesRepresentative): JsonResponse
    {
        if ($salesRepresentative->status === 'active') {
            return response()->json([
                'message' => 'Sales representative is already active.',
                'data' => $this->representativePayload($salesRepresentative->load(['user', 'assignments', 'commissions'])),
            ]);
        }

        $salesRepresentative = $this->activityService->reactivate($salesRepresentative, $request->user());

        return response()->json([
            'message' => 'Sales representative reactivated successfully.',
            'data' => $this->representativePayload($salesRepresentative),
        ]);
    }

    public function assign(Request $request, SalesRepresentative $salesRepresentative): JsonResponse
    {
        $data = $request->validate([
            'demo_booking_id' => ['nullable', 'exists:demo_bookings,id'],
            'school_id' => ['nullable', 'exists:school_settings,id'],
            'admin_user_id' => ['nullable', 'exists:users,id'],
            'stage' => ['nullable', Rule::in(['lead', 'contacted', 'demo_booked', 'proposal_sent', 'follow_up', 'converted', 'lost'])],
            'source' => ['nullable', 'string', 'max:80'],
            'pipeline_value' => ['nullable', 'numeric', 'min:0'],
            'expected_close_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        if (empty($data['demo_booking_id']) && empty($data['school_id']) && empty($data['admin_user_id'])) {
            return response()->json([
                'message' => 'Select at least one lead, school, or school admin to assign.',
            ], 422);
        }

        $assignment = SalesRepAssignment::create([
            'sales_representative_id' => $salesRepresentative->id,
            'demo_booking_id' => $data['demo_booking_id'] ?? null,
            'school_id' => $data['school_id'] ?? null,
            'admin_user_id' => $data['admin_user_id'] ?? null,
            'stage' => $data['stage'] ?? 'lead',
            'source' => $data['source'] ?? 'manual',
            'pipeline_value' => $data['pipeline_value'] ?? 0,
            'expected_close_date' => $data['expected_close_date'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json([
            'message' => 'Lead assigned successfully.',
            'data' => $assignment->load(['demoBooking', 'school', 'adminUser']),
        ], 201);
    }

    public function updateCommissionStatus(Request $request, SalesCommission $commission): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['pending', 'approved', 'paid', 'void'])],
            'notes' => ['nullable', 'string'],
        ]);

        $updates = [
            'status' => $data['status'],
            'notes' => $data['notes'] ?? $commission->notes,
        ];

        if ($data['status'] === 'approved') {
            $updates['approved_by'] = $request->user()?->id;
            $updates['approved_at'] = now();
        }

        if ($data['status'] === 'paid') {
            $updates['paid_at'] = now();
            $updates['approved_by'] = $commission->approved_by ?: $request->user()?->id;
            $updates['approved_at'] = $commission->approved_at ?: now();
        }

        $commission->update($updates);

        return response()->json([
            'message' => 'Commission status updated successfully.',
            'data' => $commission->fresh()->load(['representative.user', 'school']),
        ]);
    }

    private function summaryPayload(): array
    {
        return [
            'total_representatives' => SalesRepresentative::count(),
            'active_representatives' => SalesRepresentative::where('status', 'active')->count(),
            'assigned_leads' => SalesRepAssignment::count(),
            'converted_leads' => SalesRepAssignment::where('stage', 'converted')->count(),
            'pipeline_value' => (float) SalesRepAssignment::sum('pipeline_value'),
            'pending_commission' => (float) SalesCommission::where('status', 'pending')->sum('amount'),
            'approved_commission' => (float) SalesCommission::where('status', 'approved')->sum('amount'),
            'paid_commission' => (float) SalesCommission::where('status', 'paid')->sum('amount'),
        ];
    }

    private function representativePayload(SalesRepresentative $rep, bool $includeDetails = false): array
    {
        $assignments = $rep->assignments;
        $commissions = $rep->commissions;

        $payload = [
            'id' => $rep->id,
            'code' => $rep->code,
            'name' => trim(($rep->user?->firstname ?? '') . ' ' . ($rep->user?->surname ?? '')) ?: $rep->user?->email,
            'firstname' => $rep->user?->firstname,
            'surname' => $rep->user?->surname,
            'email' => $rep->user?->email,
            'phone' => $rep->user?->phone,
            'region' => $rep->region,
            'status' => $rep->status,
            'sales_page_url' => $this->referrals->salesPageUrl($rep->code),
            'commission_rate' => (float) $rep->commission_rate,
            'core_commission_rate' => (float) ($rep->core_commission_rate ?? $rep->commission_rate),
            'premium_commission_rate' => (float) ($rep->premium_commission_rate ?? $rep->commission_rate),
            'monthly_target_amount' => (float) $rep->monthly_target_amount,
            'monthly_target_schools' => (int) $rep->monthly_target_schools,
            'joined_at' => optional($rep->joined_at)?->toDateString(),
            'assigned_leads' => $assignments->count(),
            'converted_leads' => $assignments->where('stage', 'converted')->count(),
            'pipeline_value' => (float) $assignments->sum('pipeline_value'),
            'commission_pending' => (float) $commissions->where('status', 'pending')->sum('amount'),
            'commission_paid' => (float) $commissions->where('status', 'paid')->sum('amount'),
            'last_activity' => optional($assignments->sortByDesc('updated_at')->first()?->updated_at)?->toDateTimeString(),
            'notes' => $rep->notes,
            'status_reason' => $rep->status_reason,
            'status_changed_at' => optional($rep->status_changed_at)?->toDateTimeString(),
            'closure_requested_at' => optional($rep->closure_requested_at)?->toDateTimeString(),
            'death_reported_at' => optional($rep->death_reported_at)?->toDateTimeString(),
            'next_of_kin_name' => $rep->next_of_kin_name,
            'next_of_kin_phone' => $rep->next_of_kin_phone,
            'next_of_kin_relationship' => $rep->next_of_kin_relationship,
            'final_settlement_status' => $rep->final_settlement_status,
            'final_settlement_notes' => $rep->final_settlement_notes,
            ...$this->activityService->snapshot($rep),
        ];

        if ($includeDetails) {
            $payload['assignments'] = $rep->assignments;
            $payload['commissions'] = $rep->commissions;
            $payload['status_events'] = $rep->statusEvents()->with('actor:id,firstname,surname,email')->latest()->limit(20)->get();
        }

        return $payload;
    }

    private function generateCode(): string
    {
        do {
            $code = 'SR' . str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT);
        } while (SalesRepresentative::where('code', $code)->exists() || User::where('reg_no', $code)->exists());

        return $code;
    }

    private function generateAdminRegNo(): string
    {
        do {
            $regNo = 'R' . random_int(100000, 999999);
        } while (User::where('reg_no', $regNo)->exists());

        return $regNo;
    }

    private function sendLoginMail(SalesRepresentative $representative, string $password): array
    {
        try {
            $representative->loadMissing('user');
            Mail::to($representative->user?->email)->send(
                new SalesRepresentativeLoginMail($representative, $password, $this->loginUrl())
            );

            return [true, null];
        } catch (\Throwable $e) {
            Log::warning('Sales representative login email failed', [
                'representative_id' => $representative->id,
                'email' => $representative->user?->email,
                'error' => $e->getMessage(),
            ]);

            return [false, $e->getMessage()];
        }
    }

    private function sendSchoolAdminLoginMail(User $admin, SchoolSetting $school, string $password): array
    {
        try {
            Mail::to($admin->email)->send(
                new SchoolAdminLoginMail($admin, $school, $password, $this->loginUrl())
            );

            return [true, null];
        } catch (\Throwable $e) {
            Log::warning('Converted school admin login email failed', [
                'user_id' => $admin->id,
                'school_id' => $school->id,
                'email' => $admin->email,
                'error' => $e->getMessage(),
            ]);

            return [false, $e->getMessage()];
        }
    }

    private function loginUrl(): string
    {
        return rtrim((string) config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173')), '/') . '/login';
    }

    private function currentRepresentative(Request $request): SalesRepresentative
    {
        return SalesRepresentative::query()
            ->where('user_id', $request->user()?->id)
            ->firstOrFail();
    }
}
