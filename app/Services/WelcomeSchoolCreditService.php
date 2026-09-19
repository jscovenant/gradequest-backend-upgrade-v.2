<?php

namespace App\Services;

use App\Models\AiCreditTransaction;
use App\Models\GradequestBillingPolicy;
use App\Models\SchoolSetting;
use App\Models\SubscriptionAiUsage;
use App\Models\SubscriptionWhatsappUsage;
use App\Models\User;
use App\Models\WhatsappCreditTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WelcomeSchoolCreditService
{
    const DEFAULT_AI_CREDITS = 50;
    const DEFAULT_WHATSAPP_CREDITS = 15;

    /**
     * Grant the free welcome credit package to a specific school.
     */
    public function grantWelcomePackage(int $schoolId, ?int $userId = null): array
    {
        if ($schoolId <= 0) {
            return ['status' => 'error', 'message' => 'Invalid school ID'];
        }

        if (! $userId) {
            $admin = User::where('school_id', $schoolId)->whereIn('role', ['Admin', 'admin'])->first();
            $userId = $admin?->id ?: 0;
        }

        $policy = GradequestBillingPolicy::first();
        $aiCredits = (int) ($policy->welcome_ai_credits ?? self::DEFAULT_AI_CREDITS);
        $waCredits = (int) ($policy->welcome_whatsapp_credits ?? self::DEFAULT_WHATSAPP_CREDITS);

        if ($aiCredits <= 0) $aiCredits = self::DEFAULT_AI_CREDITS;
        if ($waCredits <= 0) $waCredits = self::DEFAULT_WHATSAPP_CREDITS;

        $results = [
            'school_id' => $schoolId,
            'ai_granted' => 0,
            'whatsapp_granted' => 0,
            'ai_total' => 0,
            'whatsapp_total' => 0,
        ];

        DB::transaction(function () use ($schoolId, $userId, $aiCredits, $waCredits, &$results) {
            // 1. Process AI Welcome Credits
            $aiReference = "WELCOME-AI-SCH-{$schoolId}";
            $existingAiTx = AiCreditTransaction::where('school_id', $schoolId)
                ->where(function ($q) use ($aiReference) {
                    $q->where('reference', $aiReference)
                      ->orWhere('feature_key', 'welcome_bonus');
                })
                ->exists();

            $aiUsage = SubscriptionAiUsage::where('school_id', $schoolId)->latest('id')->lockForUpdate()->first();

            if (! $aiUsage) {
                $aiUsage = SubscriptionAiUsage::create([
                    'subscription_id' => null,
                    'school_id' => $schoolId,
                    'user_id' => $userId,
                    'cycle_start' => now()->startOfYear()->toDateString(),
                    'cycle_end' => now()->addYears(2)->endOfYear()->toDateString(),
                    'allocated_credits' => $aiCredits,
                    'used_credits' => 0,
                ]);

                AiCreditTransaction::create([
                    'school_id' => $schoolId,
                    'subscription_ai_usage_id' => $aiUsage->id,
                    'user_id' => $userId,
                    'feature_key' => 'welcome_bonus',
                    'type' => 'allocation',
                    'credits' => $aiCredits,
                    'reference' => $aiReference,
                    'metadata' => [
                        'purpose' => 'welcome_starter_pack',
                        'granted_at' => now()->toIso8601String(),
                    ],
                ]);

                $results['ai_granted'] = $aiCredits;
            } elseif (! $existingAiTx) {
                // If usage exists but never received welcome bonus, add it
                $aiUsage->allocated_credits = (int) $aiUsage->allocated_credits + $aiCredits;
                $aiUsage->save();

                AiCreditTransaction::create([
                    'school_id' => $schoolId,
                    'subscription_ai_usage_id' => $aiUsage->id,
                    'user_id' => $userId,
                    'feature_key' => 'welcome_bonus',
                    'type' => 'allocation',
                    'credits' => $aiCredits,
                    'reference' => $aiReference,
                    'metadata' => [
                        'purpose' => 'welcome_starter_pack',
                        'granted_at' => now()->toIso8601String(),
                    ],
                ]);

                $results['ai_granted'] = $aiCredits;
            }

            $results['ai_total'] = (int) $aiUsage->fresh()->allocated_credits - (int) $aiUsage->fresh()->used_credits;

            // 2. Process WhatsApp Welcome Credits
            $waReference = "WELCOME-WA-SCH-{$schoolId}";
            $existingWaTx = WhatsappCreditTransaction::where('school_id', $schoolId)
                ->where('reference', $waReference)
                ->exists();

            $waUsage = SubscriptionWhatsappUsage::where('school_id', $schoolId)->latest('id')->lockForUpdate()->first();

            if (! $waUsage) {
                $waUsage = SubscriptionWhatsappUsage::create([
                    'subscription_id' => null,
                    'school_id' => $schoolId,
                    'user_id' => $userId,
                    'cycle_start' => now()->startOfYear()->toDateString(),
                    'cycle_end' => now()->addYears(2)->endOfYear()->toDateString(),
                    'allocated_credits' => $waCredits,
                    'used_credits' => 0,
                ]);

                WhatsappCreditTransaction::create([
                    'school_id' => $schoolId,
                    'subscription_whatsapp_usage_id' => $waUsage->id,
                    'type' => 'allocation',
                    'credits' => $waCredits,
                    'reference' => $waReference,
                    'metadata' => [
                        'purpose' => 'welcome_starter_pack',
                        'granted_at' => now()->toIso8601String(),
                    ],
                ]);

                $results['whatsapp_granted'] = $waCredits;
            } elseif (! $existingWaTx) {
                $waUsage->allocated_credits = (int) $waUsage->allocated_credits + $waCredits;
                $waUsage->save();

                WhatsappCreditTransaction::create([
                    'school_id' => $schoolId,
                    'subscription_whatsapp_usage_id' => $waUsage->id,
                    'type' => 'allocation',
                    'credits' => $waCredits,
                    'reference' => $waReference,
                    'metadata' => [
                        'purpose' => 'welcome_starter_pack',
                        'granted_at' => now()->toIso8601String(),
                    ],
                ]);

                $results['whatsapp_granted'] = $waCredits;
            }

            $results['whatsapp_total'] = (int) $waUsage->fresh()->allocated_credits - (int) $waUsage->fresh()->used_credits;

            // 3. Ensure School Setting is Active with Standard CBT tier enabled for testing
            $schoolSetting = SchoolSetting::find($schoolId);
            if ($schoolSetting && empty($schoolSetting->active_edition_tier)) {
                $schoolSetting->active_edition_tier = 'standard_cbt';
                $schoolSetting->save();
            }
        });

        return $results;
    }

    /**
     * Grant welcome credits to all existing schools in the database.
     */
    public function grantToAllExistingSchools(): array
    {
        $schools = SchoolSetting::all();
        $total = $schools->count();
        $grantedCount = 0;
        $summary = [];

        foreach ($schools as $school) {
            try {
                $res = $this->grantWelcomePackage($school->id, $school->user_id);
                if ($res['ai_granted'] > 0 || $res['whatsapp_granted'] > 0) {
                    $grantedCount++;
                }
                $summary[] = [
                    'school_id' => $school->id,
                    'school_name' => $school->school_name,
                    'ai_granted' => $res['ai_granted'],
                    'whatsapp_granted' => $res['whatsapp_granted'],
                    'ai_balance' => $res['ai_total'],
                    'whatsapp_balance' => $res['whatsapp_total'],
                ];
            } catch (\Throwable $e) {
                Log::error("Failed to grant welcome credits to school {$school->id}: " . $e->getMessage());
            }
        }

        return [
            'total_schools' => $total,
            'granted_schools' => $grantedCount,
            'summary' => $summary,
        ];
    }
}
