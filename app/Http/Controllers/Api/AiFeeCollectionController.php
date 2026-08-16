<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AutoFeeInvoiceService;
use App\Services\Fees\AiFeeCollectionAssistantService;
use App\Services\SubscriptionAiCreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class AiFeeCollectionController extends Controller
{
    public function analyze(Request $request, AiFeeCollectionAssistantService $assistant, SubscriptionAiCreditService $credits): JsonResponse
    {
        $auth = $request->user();
        abort_unless(in_array(strtolower((string) ($auth->role ?? '')), ['admin', 'bursar'], true), 403, 'Unauthorized.');

        $data = $request->validate([
            'session_id' => ['nullable', 'integer', 'exists:academic_sessions,id'],
            'term_id' => ['nullable', 'integer', 'exists:terms,id'],
            'class_id' => ['nullable', 'integer', 'exists:student_classes,id'],
            'section_id' => ['nullable', 'integer', 'exists:sections,id'],
            'limit' => ['nullable', 'integer', 'min:3', 'max:25'],
        ]);

        $schoolId = (int) ($auth->school_id ?? 0);
        $featureKey = 'ai_fee_collection_assistant';
        $creditCost = $credits->costForFeature($featureKey);
        $credits->assertCreditsAvailable($schoolId, $featureKey, $creditCost);

        try {
            $result = $assistant->analyze($schoolId, $data);
        } catch (Throwable $exception) {
            $this->logAiUsage($request, 'failed', [], ['error' => $exception->getMessage(), 'filters' => $data]);
            return response()->json(['message' => 'Something went wrong while processing this AI request. Please try again later.'], 422);
        }

        $chargeable = (float) data_get($result, 'analysis.summary.total_balance', 0) > 0;
        $usage = null;
        if ($chargeable) {
            $usage = $credits->consumeCredits($schoolId, $featureKey, $creditCost, 'ai-fee-collection:' . $schoolId . ':' . now()->format('YmdHis') . ':' . bin2hex(random_bytes(4)), [
                'filters' => $data,
                'total_balance' => data_get($result, 'analysis.summary.total_balance'),
                'owing_parents' => data_get($result, 'analysis.summary.owing_parents'),
            ]);
        }

        $this->logAiUsage($request, 'success', $result['usage'] ?? [], [
            'filters' => $data,
            'summary' => data_get($result, 'analysis.summary'),
        ], $usage?->id, $chargeable ? $creditCost : 0);

        return response()->json([
            'message' => 'Fee collection analysis generated successfully.',
            'analysis' => $result['analysis'],
            'ai_credits' => [
                'charged' => $chargeable ? $creditCost : 0,
                'remaining' => $usage?->remainingCredits(),
                'allocated' => $usage ? (int) $usage->allocated_credits : null,
                'used' => $usage ? (int) $usage->used_credits : null,
            ],
        ]);
    }


    public function sendReminder(Request $request, AutoFeeInvoiceService $reminders): JsonResponse
    {
        $auth = $request->user();
        abort_unless(in_array(strtolower((string) ($auth->role ?? '')), ['admin', 'bursar'], true), 403, 'Unauthorized.');

        $data = $request->validate([
            'parent_id' => ['required', 'integer', 'exists:users,id'],
            'session_id' => ['nullable', 'integer', 'exists:academic_sessions,id'],
            'term_id' => ['nullable', 'integer', 'exists:terms,id'],
            'class_id' => ['nullable', 'integer', 'exists:student_classes,id'],
            'section_id' => ['nullable', 'integer', 'exists:sections,id'],
        ]);

        $schoolId = (int) ($auth->school_id ?? 0);
        $parentId = (int) $data['parent_id'];
        unset($data['parent_id']);

        $result = $reminders->sendManualParentReminder($schoolId, $parentId, $data);
        $status = ($result['delivered'] ?? false) ? 200 : 422;

        return response()->json($result, $status);
    }
    private function logAiUsage(Request $request, string $status, array $usage = [], array $metadata = [], ?int $creditUsageId = null, int $creditsCharged = 0): void
    {
        if (! DB::getSchemaBuilder()->hasTable('ai_usage_logs')) {
            return;
        }

        DB::table('ai_usage_logs')->insert([
            'school_id' => $request->user()?->school_id,
            'user_id' => $request->user()?->id,
            'subscription_ai_usage_id' => $creditUsageId,
            'feature_key' => 'ai_fee_collection_assistant',
            'provider' => 'openai',
            'model' => $usage['model'] ?? config('openai.model'),
            'status' => $status,
            'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
            'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
            'items_generated' => (int) (data_get($metadata, 'summary.owing_parents', 0) ?: 0),
            'credits_charged' => $creditsCharged,
            'metadata' => json_encode($metadata),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
