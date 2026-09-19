<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiSalesAgentConfig;
use App\Models\AiSalesConversation;
use App\Models\AiSalesOutreachLog;
use App\Services\Ai\AiSalesAgentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

use App\Models\GradequestBillingPolicy;

class AiSalesAgentController extends Controller
{
    protected AiSalesAgentService $salesService;

    public function __construct(AiSalesAgentService $salesService)
    {
        $this->salesService = $salesService;
    }

    /**
     * Public / Sandbox Chat endpoint for the AI Sales Agent.
     */
    public function chat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => 'nullable|string|max:100',
            'messages' => 'required|array|min:1',
            'messages.*.role' => 'required|string|in:user,assistant',
            'messages.*.content' => 'required|string|max:4000',
            'prospect_name' => 'nullable|string|max:150',
            'school_name' => 'nullable|string|max:200',
            'phone_number' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:150',
            'channel' => 'nullable|string|max:40',
        ]);

        $sessionId = $validated['session_id'] ?? ('sandbox_' . Str::random(16));

        try {
            $result = $this->salesService->chat($sessionId, $validated['messages'], $validated);

            return response()->json([
                'status' => true,
                'session_id' => $result['session_id'],
                'reply' => $result['reply'],
                'conversation' => $result['conversation'],
                'usage' => $result['usage'],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Public info endpoint for the frontend floating chat widget.
     */
    public function getPublicInfo(): JsonResponse
    {
        $config = AiSalesAgentConfig::getActiveConfig();
        $billingPolicy = GradequestBillingPolicy::first();

        return response()->json([
            'status' => true,
            'agent_name' => $config->agent_name ?: 'Sarah - SchoolProfit Growth Consultant',
            'support_whatsapp_number' => $config->support_whatsapp_number ?: '+2348000000000',
            'demo_booking_url' => $config->demo_booking_url ?: 'https://schoolprofit.ng/book-demo',
            'rates' => [
                'basic_tier_price_per_student' => (float) ($billingPolicy->basic_tier_price_per_student ?? 300),
                'standard_cbt_tier_price_per_student' => (float) ($billingPolicy->standard_cbt_tier_price_per_student ?? 500),
            ],
        ]);
    }

    /**
     * Get Super-Admin AI Sales Agent Configuration.
     */
    public function getConfig(): JsonResponse
    {
        $config = AiSalesAgentConfig::getActiveConfig();
        $billingPolicy = GradequestBillingPolicy::first();

        return response()->json([
            'status' => true,
            'config' => $config,
            'billing_rates' => [
                'basic_tier_price_per_student' => (float) ($billingPolicy->basic_tier_price_per_student ?? 300),
                'standard_cbt_tier_price_per_student' => (float) ($billingPolicy->standard_cbt_tier_price_per_student ?? 500),
                'platform_fee_per_student' => (float) ($billingPolicy->platform_fee_per_student ?? 500),
                'default_bank_charge_amount' => (float) ($billingPolicy->default_bank_charge_amount ?? 200),
                'whatsapp_credit_unit_price' => (float) ($billingPolicy->whatsapp_credit_unit_price ?? 10),
            ],
        ]);
    }

    /**
     * Update Super-Admin AI Sales Agent Configuration.
     */
    public function updateConfig(Request $request): JsonResponse
    {
        $config = AiSalesAgentConfig::getActiveConfig();

        $validated = $request->validate([
            'openai_api_key' => 'nullable|string|max:255',
            'model' => 'required|string|in:gpt-4o-mini,gpt-4o,gpt-3.5-turbo,gpt-4-turbo',
            'temperature' => 'required|numeric|min:0|max:1',
            'agent_name' => 'required|string|max:100',
            'custom_instructions' => 'nullable|string|max:5000',
            'inactivity_threshold_days' => 'required|integer|min:3|max:90',
            'auto_scan_enabled' => 'required|boolean',
            'whatsapp_outreach_enabled' => 'required|boolean',
            'max_daily_outreach' => 'required|integer|min:5|max:500',
            'support_whatsapp_number' => 'nullable|string|max:50',
            'demo_booking_url' => 'nullable|string|max:255',
            'signup_url' => 'nullable|string|max:255',
            'pricing_url' => 'nullable|string|max:255',
        ]);

        $config->update($validated);

        return response()->json([
            'status' => true,
            'message' => 'AI Sales Agent settings updated successfully.',
            'config' => $config->fresh(),
        ]);
    }

    /**
     * Run Inactive Schools Scanner & Return Outreach Queue.
     */
    public function getInactiveSchools(Request $request): JsonResponse
    {
        $config = AiSalesAgentConfig::getActiveConfig();
        $thresholdDays = (int) $request->input('threshold_days', $config->inactivity_threshold_days ?: 14);

        $staged = $this->salesService->scanInactiveSchools($thresholdDays);
        $recentLogs = AiSalesOutreachLog::with('school')->latest()->take(50)->get();

        return response()->json([
            'status' => true,
            'staged_count' => count($staged),
            'staged_schools' => $staged,
            'recent_logs' => $recentLogs,
        ]);
    }

    /**
     * Mark or Dispatch WhatsApp outreach log.
     */
    public function dispatchOutreach(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'log_id' => 'required|integer|exists:ai_sales_outreach_logs,id',
            'status' => 'required|string|in:sent,delivered,replied,converted,opted_out',
            'notes' => 'nullable|string|max:1000',
        ]);

        $log = AiSalesOutreachLog::findOrFail($validated['log_id']);
        $updates = [
            'status' => $validated['status'],
            'response_notes' => $validated['notes'] ?? $log->response_notes,
        ];

        if ($validated['status'] === 'sent' && ! $log->dispatched_at) {
            $updates['dispatched_at'] = now();
        } elseif ($validated['status'] === 'replied') {
            $updates['replied_at'] = now();
        } elseif ($validated['status'] === 'converted') {
            $updates['converted_at'] = now();
        }

        $log->update($updates);

        return response()->json([
            'status' => true,
            'message' => 'Outreach log status updated to ' . ucfirst($validated['status']),
            'log' => $log->fresh(),
        ]);
    }

    /**
     * Get Conversation History & Lead Pipeline.
     */
    public function getConversations(Request $request): JsonResponse
    {
        $query = AiSalesConversation::query()->latest('last_interaction_at');

        if ($channel = $request->input('channel')) {
            if ($channel !== 'all') {
                $query->where('channel', $channel);
            }
        }

        if ($status = $request->input('status')) {
            if ($status !== 'all') {
                $query->where('lead_status', $status);
            }
        }

        $conversations = $query->paginate(20);

        return response()->json([
            'status' => true,
            'conversations' => $conversations,
        ]);
    }

    /**
     * Get AI Sales Agent Performance Analytics.
     */
    public function getAnalytics(): JsonResponse
    {
        $totalConversations = AiSalesConversation::count();
        $qualifiedLeads = AiSalesConversation::whereIn('lead_status', ['qualified', 'demo_booked', 'signup_initiated', 'closed_won'])->count();
        $totalOutreachSent = AiSalesOutreachLog::whereIn('status', ['sent', 'delivered', 'replied', 'converted'])->count();
        $totalConverted = AiSalesOutreachLog::where('status', 'converted')->count() + AiSalesConversation::where('lead_status', 'closed_won')->count();

        $conversionRate = $totalConversations > 0 ? round(($qualifiedLeads / $totalConversations) * 100, 1) : 0;

        return response()->json([
            'status' => true,
            'analytics' => [
                'total_conversations' => $totalConversations,
                'qualified_leads' => $qualifiedLeads,
                'outreach_sent' => $totalOutreachSent,
                'converted_schools' => $totalConverted,
                'conversion_rate_pct' => $conversionRate,
            ],
        ]);
    }
}
