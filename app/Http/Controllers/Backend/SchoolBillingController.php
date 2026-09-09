<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\GradiosEduTermInvoice;
use App\Models\SchoolBillingAuditLog;
use App\Services\SchoolBillingService;
use Illuminate\Http\Request;

class SchoolBillingController extends Controller
{
    public function __construct(private SchoolBillingService $billing)
    {
    }

    public function dashboard(Request $request)
    {
        return response()->json($this->billing->dashboard((int) $request->user()->school_id));
    }

    public function settings(Request $request)
    {
        $schoolId = (int) $request->user()->school_id;

        return response()->json([
            'settings' => $this->billing->settingsForSchool($schoolId),
            'switch_check' => $this->billing->canSwitchPaymentMode($schoolId),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'payment_mode' => 'required|in:online,offline',
        ]);

        $validated['block_results_when_unpaid'] = true;

        $settings = $this->billing->updateSettings(
            (int) $request->user()->school_id,
            $validated,
            (int) $request->user()->id
        );

        return response()->json([
            'message' => 'Billing settings updated.',
            'settings' => $settings,
            'switch_check' => $this->billing->canSwitchPaymentMode((int) $request->user()->school_id),
        ]);
    }

    public function generateOfflineInvoice(Request $request)
    {
        $validated = $request->validate([
            'session_id' => 'nullable|exists:academic_sessions,id',
            'term_id' => 'nullable|exists:terms,id',
        ]);

        $schoolId = (int) $request->user()->school_id;
        [$session, $term] = $this->billing->currentPeriod($schoolId);

        $sessionId = (int) ($validated['session_id'] ?? $session?->id);
        $termId = (int) ($validated['term_id'] ?? $term?->id);

        if (! $sessionId || ! $termId) {
            return response()->json(['message' => 'Current academic session or active term is not set.'], 422);
        }

        $invoice = $this->billing->generateOfflineInvoice($schoolId, $sessionId, $termId, (int) $request->user()->id);

        return response()->json([
            'message' => 'Offline SchoolProfit invoice generated.',
            'invoice' => $invoice,
        ]);
    }

    public function generateSessionInvoice(Request $request)
    {
        $validated = $request->validate([
            'session_id' => 'nullable|exists:academic_sessions,id',
        ]);

        $schoolId = (int) $request->user()->school_id;
        [$session, $term] = $this->billing->currentPeriod($schoolId);

        $sessionId = (int) ($validated['session_id'] ?? $session?->id);

        if (! $sessionId) {
            return response()->json(['message' => 'Current academic session is not set.'], 422);
        }

        $invoice = $this->billing->generateSessionInvoice($schoolId, $sessionId, (int) $request->user()->id);

        return response()->json([
            'message' => 'Full session SchoolProfit invoice generated.',
            'invoice' => $invoice,
        ]);
    }

    public function recordInvoicePayment(Request $request, GradiosEduTermInvoice $invoice)
    {
        abort_unless($this->isPlatformUser($request), 403, 'Only GradiosEdu can record invoice payments.');

        $validated = $request->validate([
            'amount' => 'required|numeric|min:1',
            'reason' => 'nullable|string|max:255',
        ]);

        $invoice = $this->billing->recordOfflineInvoicePayment(
            $invoice,
            (float) $validated['amount'],
            (int) $request->user()->id,
            $validated['reason'] ?? null
        );

        return response()->json([
            'message' => 'Invoice payment recorded.',
            'invoice' => $invoice,
        ]);
    }

    public function audits(Request $request)
    {
        $logs = SchoolBillingAuditLog::where('school_id', $request->user()->school_id)
            ->latest()
            ->paginate((int) $request->query('per_page', 20));

        return response()->json($logs);
    }

    public function invoices(Request $request)
    {
        $query = GradiosEduTermInvoice::query()->latest();

        if (! $this->isPlatformUser($request)) {
            $query->where('school_id', $request->user()->school_id);
        }

        return response()->json($query->paginate((int) $request->query('per_page', 20)));
    }

    private function isPlatformUser(Request $request): bool
    {
        $user = $request->user();

        return $user && $user->isSuperAdminUser();
    }
}
