<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\Investor;
use App\Models\InvestorDisbursement;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class InvestorManagementController extends Controller
{
    /**
     * Display a listing of investors and overall portfolio metrics.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Investor::with(['user:id,email,phone,created_at', 'disbursements']);

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('tier')) {
            $query->where('tier', $request->query('tier'));
        }

        if ($request->filled('search')) {
            $s = trim($request->query('search'));
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                    ->orWhere('email', 'like', "%{$s}%")
                    ->orWhere('phone', 'like', "%{$s}%")
                    ->orWhere('contract_ref', 'like', "%{$s}%");
            });
        }

        $investors = $query->latest('investment_date')->get();

        $metrics = [
            'total_capital_raised' => (float) Investor::sum('capital_invested'),
            'total_principal_repaid' => (float) Investor::sum('total_principal_repaid'),
            'total_dividends_paid' => (float) Investor::sum('total_dividends_paid'),
            'total_investors_count' => Investor::count(),
            'active_investors_count' => Investor::where('status', 'active')->count(),
            'recoup_phase_count' => Investor::where('current_phase', 'recoup_phase')->count(),
            'dividend_phase_count' => Investor::where('current_phase', 'dividend_phase')->count(),
            'tier_distribution' => [
                'bronze' => Investor::where('tier', 'bronze')->count(),
                'silver' => Investor::where('tier', 'silver')->count(),
                'gold' => Investor::where('tier', 'gold')->count(),
                'custom' => Investor::where('tier', 'custom')->count(),
            ],
        ];

        return response()->json([
            'status' => true,
            'message' => 'Investors retrieved successfully',
            'metrics' => $metrics,
            'investors' => $investors,
        ]);
    }

    /**
     * Onboard a new strategic investor and optionally create a login user account.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'email' => 'required|email|max:150|unique:investors,email',
            'phone' => 'nullable|string|max:30',
            'tier' => 'required|in:bronze,silver,gold,custom',
            'capital_invested' => 'required|numeric|min:1000',
            'equity_percentage' => 'required|numeric|min:0.1|max:100',
            'recoup_percentage' => 'nullable|numeric|min:1|max:100',
            'tenure_years' => 'nullable|integer|min:1|max:20',
            'investment_date' => 'nullable|date',
            'bank_name' => 'nullable|string|max:100',
            'account_number' => 'nullable|string|max:30',
            'account_name' => 'nullable|string|max:150',
            'contract_ref' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
            'create_user_account' => 'nullable|boolean',
            'user_password' => 'nullable|string|min:6',
        ]);

        return DB::transaction(function () use ($validated) {
            $userId = null;

            // Check if user account creation was requested or existing user with email exists
            $existingUser = User::where('email', $validated['email'])->first();
            if ($existingUser) {
                $userId = $existingUser->id;
                $existingUser->role = User::ROLE_INVESTOR;
                $existingUser->save();
            } elseif (!empty($validated['create_user_account'])) {
                $password = !empty($validated['user_password']) ? $validated['user_password'] : 'Invest@' . date('Y');
                $user = User::create([
                    'first_name' => explode(' ', $validated['name'])[0] ?? $validated['name'],
                    'last_name' => explode(' ', $validated['name'])[1] ?? 'Partner',
                    'email' => $validated['email'],
                    'phone' => $validated['phone'] ?? null,
                    'role' => User::ROLE_INVESTOR,
                    'password' => Hash::make($password),
                    'default_password' => $password,
                    'force_password_change' => false,
                ]);
                $userId = $user->id;
            }

            $investor = Investor::create([
                'user_id' => $userId,
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'tier' => $validated['tier'],
                'capital_invested' => $validated['capital_invested'],
                'equity_percentage' => $validated['equity_percentage'],
                'recoup_percentage' => $validated['recoup_percentage'] ?? 25.00,
                'tenure_years' => $validated['tenure_years'] ?? 5,
                'total_principal_repaid' => 0.00,
                'total_dividends_paid' => 0.00,
                'current_phase' => 'recoup_phase',
                'investment_date' => $validated['investment_date'] ?? now()->toDateString(),
                'bank_name' => $validated['bank_name'] ?? null,
                'account_number' => $validated['account_number'] ?? null,
                'account_name' => $validated['account_name'] ?? null,
                'contract_ref' => $validated['contract_ref'] ?? 'ST/SP-INV/2026/01',
                'status' => 'active',
                'notes' => $validated['notes'] ?? null,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Investor successfully registered and onboarded',
                'investor' => $investor->load('disbursements'),
            ], 201);
        });
    }

    /**
     * Display detailed investor record with disbursement history.
     */
    public function show(int $id): JsonResponse
    {
        $investor = Investor::with(['user:id,email,phone,created_at', 'disbursements'])->findOrFail($id);

        return response()->json([
            'status' => true,
            'investor' => $investor,
        ]);
    }

    /**
     * Update investor profile, banking details, or status.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $investor = Investor::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:150',
            'phone' => 'nullable|string|max:30',
            'bank_name' => 'nullable|string|max:100',
            'account_number' => 'nullable|string|max:30',
            'account_name' => 'nullable|string|max:150',
            'tier' => 'sometimes|in:bronze,silver,gold,custom',
            'equity_percentage' => 'sometimes|numeric|min:0.1|max:100',
            'recoup_percentage' => 'sometimes|numeric|min:1|max:100',
            'current_phase' => 'sometimes|in:recoup_phase,dividend_phase,completed',
            'status' => 'sometimes|in:active,paused,completed',
            'notes' => 'nullable|string',
        ]);

        $investor->update($validated);

        return response()->json([
            'status' => true,
            'message' => 'Investor details updated successfully',
            'investor' => $investor->load('disbursements'),
        ]);
    }

    /**
     * Record a termly principal recoup or 5-year dividend disbursement.
     */
    public function recordDisbursement(Request $request, int $id): JsonResponse
    {
        $investor = Investor::findOrFail($id);

        $validated = $request->validate([
            'type' => 'required|in:principal_recoup,dividend_payout',
            'amount' => 'required|numeric|min:1',
            'academic_session' => 'nullable|string|max:30',
            'term' => 'nullable|string|max:30',
            'payment_date' => 'required|date',
            'payment_method' => 'nullable|string|max:30',
            'bank_reference' => 'nullable|string|max:100',
            'platform_term_collection' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($investor, $validated, $request) {
            $prefix = $validated['type'] === 'principal_recoup' ? 'ST-RECP-' : 'ST-DIV-';
            $ref = $prefix . date('Ymd') . '-' . strtoupper(Str::random(4));

            $disbursement = InvestorDisbursement::create([
                'investor_id' => $investor->id,
                'reference' => $ref,
                'type' => $validated['type'],
                'academic_session' => $validated['academic_session'] ?? null,
                'term' => $validated['term'] ?? null,
                'amount' => $validated['amount'],
                'platform_term_collection' => $validated['platform_term_collection'] ?? null,
                'payment_method' => $validated['payment_method'] ?? 'bank_transfer',
                'payment_date' => $validated['payment_date'],
                'bank_reference' => $validated['bank_reference'] ?? null,
                'status' => 'completed',
                'notes' => $validated['notes'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            // Update Investor Running Financials
            if ($validated['type'] === 'principal_recoup') {
                $investor->total_principal_repaid = (float) $investor->total_principal_repaid + (float) $validated['amount'];
                
                // If principal is 100% repaid, automatically advance to Phase 2 (Dividend Phase)!
                if ($investor->total_principal_repaid >= (float) $investor->capital_invested) {
                    $investor->current_phase = 'dividend_phase';
                }
            } else {
                $investor->total_dividends_paid = (float) $investor->total_dividends_paid + (float) $validated['amount'];
            }

            $investor->save();

            return response()->json([
                'status' => true,
                'message' => 'Disbursement recorded and credited successfully',
                'disbursement' => $disbursement,
                'investor' => $investor->fresh(['disbursements']),
            ], 201);
        });
    }

    /**
     * Remove or archive an investor record.
     */
    public function destroy(int $id): JsonResponse
    {
        $investor = Investor::findOrFail($id);

        if ($investor->disbursements()->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'Cannot delete investor with existing disbursement payment records. You can set their status to paused instead.',
            ], 422);
        }

        $investor->delete();

        return response()->json([
            'status' => true,
            'message' => 'Investor record deleted successfully',
        ]);
    }
}
