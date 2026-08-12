<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Lessons\AiLessonPlanGeneratorService;
use App\Services\SubscriptionAiCreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class AiLessonPlanController extends Controller
{
    public function generate(Request $request, AiLessonPlanGeneratorService $generator, SubscriptionAiCreditService $credits): JsonResponse
    {
        $auth = $request->user();
        abort_unless(in_array(strtolower((string) ($auth->role ?? '')), ['admin', 'teacher', 'principal'], true), 403, 'Unauthorized.');

        $data = $request->validate([
            'subject' => 'required|string|max:120',
            'class' => 'required|string|max:120',
            'topic' => 'required|string|max:180',
            'duration_minutes' => 'required|integer|min:10|max:240',
            'teacher_notes' => 'nullable|string|max:4000',
        ]);

        $schoolId = (int) ($auth->school_id ?? 0);
        $featureKey = 'ai_lesson_plan_generator';
        $creditCost = $credits->costForFeature($featureKey);
        $credits->assertCreditsAvailable($schoolId, $featureKey, $creditCost);

        try {
            $result = $generator->generate($data);
        } catch (Throwable $exception) {
            $this->logAiUsage($request, 'failed', [], ['error' => $exception->getMessage(), 'input' => $data]);
            return response()->json(['message' => 'Something went wrong while processing this AI request. Please try again later.'], 422);
        }

        $usage = $credits->consumeCredits($schoolId, $featureKey, $creditCost, 'ai-lesson-plan:' . $schoolId . ':' . now()->format('YmdHis') . ':' . bin2hex(random_bytes(4)), [
            'subject' => $data['subject'],
            'class' => $data['class'],
            'topic' => $data['topic'],
            'duration_minutes' => $data['duration_minutes'],
        ]);

        $this->logAiUsage($request, 'success', $result['usage'] ?? [], [
            'input' => $data,
            'lesson_title' => $result['lesson_plan']['title'] ?? null,
        ], $usage->id, $creditCost);

        return response()->json([
            'message' => 'Lesson plan generated successfully.',
            'lesson_plan' => $result['lesson_plan'],
            'ai_credits' => [
                'charged' => $creditCost,
                'remaining' => $usage->remainingCredits(),
                'allocated' => (int) $usage->allocated_credits,
                'used' => (int) $usage->used_credits,
            ],
        ]);
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
            'feature_key' => 'ai_lesson_plan_generator',
            'provider' => 'openai',
            'model' => $usage['model'] ?? config('openai.model'),
            'status' => $status,
            'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
            'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
            'items_generated' => $status === 'success' ? 1 : 0,
            'credits_charged' => $creditsCharged,
            'metadata' => json_encode($metadata),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
