<?php

namespace App\Services;

use App\Models\SchoolWhatsappAccount;
use App\Models\User;
use App\Models\WhatsappMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SchoolWhatsappMessagingService
{
    public function __construct(
        private WhatsAppCloudClient $client,
        private SubscriptionWhatsappCreditService $credits
    ) {}

    public function sendToParent(
        int $schoolId,
        int $parentUserId,
        ?int $studentUserId,
        string $templateName,
        string $lang = 'en',
        array $bodyParams = [],
        int $creditCost = 1
    ): WhatsappMessage {
        $parent = User::query()
            ->where('id', $parentUserId)
            ->where('school_id', $schoolId)
            ->where('role', 'Parent')
            ->first();

        if (!$parent) {
            throw ValidationException::withMessages([
                'parent' => 'Parent not found.',
            ]);
        }

        if (!$parent->whatsapp_no || !$parent->whatsapp_verified_at) {
            throw ValidationException::withMessages([
                'parent' => 'Parent WhatsApp number is not verified.',
            ]);
        }

        $account = SchoolWhatsappAccount::query()
            ->where('school_id', $schoolId)
            ->where('status', 'active')
            ->first();

        if (!$account) {
            throw ValidationException::withMessages([
                'whatsapp' => 'School WhatsApp business account is not active.',
            ]);
        }

        $usage = $this->credits->assertCreditsAvailable($schoolId, $creditCost);
        $subscriptionId = (int) $usage->subscription_id;

        $normalizedPhone = $this->client->normalizePhone($parent->whatsapp_no);

        $message = WhatsappMessage::create([
            'school_id' => $schoolId,
            'subscription_id' => $subscriptionId,
            'parent_user_id' => $parent->id,
            'student_user_id' => $studentUserId,
            'school_whatsapp_account_id' => $account->id,
            'to_phone' => $parent->whatsapp_no,
            'normalized_phone' => $normalizedPhone,
            'template_name' => $templateName,
            'template_lang' => $lang,
            'status' => 'queued',
            'credit_cost' => $creditCost,
            'payload' => [
                'body_params' => $bodyParams,
            ],
        ]);

        try {
            $response = $this->client->sendTemplateForSchool(
                schoolId: $schoolId,
                toPhone: $normalizedPhone,
                templateName: $templateName,
                lang: $lang,
                bodyParams: $bodyParams
            );

            DB::transaction(function () use ($schoolId, $creditCost, $message, $response) {
                $this->credits->consumeCredits($schoolId, $creditCost);

                $message->update([
                    'status' => 'sent',
                    'meta_message_id' => data_get($response, 'messages.0.id'),
                    'meta_response' => $response,
                    'sent_at' => now(),
                ]);
            });

            return $message->fresh();
        } catch (\Throwable $e) {
            $message->update([
                'status' => 'failed',
                'failure_reason' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}