<?php

namespace App\Services\Ai;

use App\Models\AiSalesAgentConfig;
use App\Models\AiSalesConversation;
use App\Models\AiSalesOutreachLog;
use App\Models\GradequestBillingPolicy;
use App\Models\SchoolSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AiSalesAgentService
{
    protected AiSalesAgentConfig $config;

    public function __construct()
    {
        $this->config = AiSalesAgentConfig::getActiveConfig();
    }

    /**
     * Process a multi-turn conversation with the AI Sales Agent.
     */
    public function chat(string $sessionId, array $newMessages, array $context = []): array
    {
        $conversation = AiSalesConversation::firstOrCreate(
            ['session_id' => $sessionId],
            [
                'channel' => $context['channel'] ?? 'web_sandbox',
                'prospect_name' => $context['prospect_name'] ?? null,
                'school_name' => $context['school_name'] ?? null,
                'phone_number' => $context['phone_number'] ?? null,
                'email' => $context['email'] ?? null,
                'messages' => [],
                'lead_status' => 'inquiry',
            ]
        );

        $existingMessages = $conversation->messages ?: [];
        $mergedMessages = array_merge($existingMessages, $newMessages);

        $apiKey = $this->config->openai_api_key ?: config('openai.api_key') ?: env('OPENAI_API_KEY');
        $replyContent = null;
        $usage = [];

        if ($apiKey) {
            try {
                // Build OpenAI Chat Completions payload
                $systemPrompt = $this->buildSystemPrompt($context);

                $openAiMessages = [
                    ['role' => 'system', 'content' => $systemPrompt],
                ];

                foreach ($mergedMessages as $msg) {
                    $openAiMessages[] = [
                        'role' => $msg['role'] === 'user' ? 'user' : 'assistant',
                        'content' => $msg['content'] ?? '',
                    ];
                }

                $model = $this->config->model ?: 'gpt-4o-mini';
                $temperature = (float) ($this->config->temperature ?: 0.70);

                $response = Http::withToken($apiKey)
                    ->connectTimeout(15)
                    ->timeout(60)
                    ->acceptJson()
                    ->post('https://api.openai.com/v1/chat/completions', [
                        'model' => $model,
                        'messages' => $openAiMessages,
                        'temperature' => $temperature,
                        'max_tokens' => 1200,
                    ]);

                if ($response->successful()) {
                    $responseData = $response->json();
                    $replyContent = $responseData['choices'][0]['message']['content'] ?? null;
                    $usage = $responseData['usage'] ?? [];
                } else {
                    Log::warning('OpenAI Sales Agent fallback activated due to API response: ' . $response->body());
                }
            } catch (\Exception $e) {
                Log::warning('OpenAI Sales Agent fallback activated due to exception: ' . $e->getMessage());
            }
        }

        // Use smart dynamic fallback if OpenAI was not called or failed
        if (! $replyContent) {
            $replyContent = $this->generateFallbackReply($mergedMessages, $context);
        }

        // Append assistant response to messages
        $mergedMessages[] = [
            'role' => 'assistant',
            'content' => $replyContent,
            'timestamp' => now()->toISOString(),
        ];

        // Parse and update conversation metadata
        $this->extractLeadMetadata($conversation, $mergedMessages);

        $conversation->update([
            'messages' => $mergedMessages,
            'message_count' => count($mergedMessages),
            'last_interaction_at' => now(),
        ]);

        return [
            'session_id' => $sessionId,
            'reply' => $replyContent,
            'conversation' => $conversation->fresh(),
            'usage' => $usage,
        ];
    }

    /**
     * Generate personalized WhatsApp re-engagement message for a dormant school owner.
     */
    public function generateReengagementMessage(SchoolSetting $school, ?User $owner, string $inactivityReason): string
    {
        $apiKey = $this->config->openai_api_key ?: config('openai.api_key') ?: env('OPENAI_API_KEY');
        $schoolName = $school->school_name ?: 'Your School';
        $ownerName = $owner ? "{$owner->firstname} {$owner->surname}" : 'School Administrator';
        $bookingUrl = $this->config->demo_booking_url ?: 'https://schoolprofit.ng/book-demo';
        $supportWhatsApp = $this->config->support_whatsapp_number ?: '+2348000000000';

        $billingPolicy = GradequestBillingPolicy::first();
        $basicPrice = $billingPolicy ? number_format((float) ($billingPolicy->basic_tier_price_per_student ?? 300), 0) : '300';
        $cbtPrice = $billingPolicy ? number_format((float) ($billingPolicy->standard_cbt_tier_price_per_student ?? 500), 0) : '500';

        $reasonDescriptions = [
            '0_students_uploaded' => "The school registered their portal on SchoolProfit but has not uploaded their student list or completed setup.",
            'no_login_30_days' => "The school administrator has not logged into their SchoolProfit dashboard for several weeks.",
            'trial_expiring' => "The school is preparing for the term and has not yet activated automated fee collections or broadsheet generation.",
            'abandoned_fee_setup' => "The school started setting up tuition items but hasn't activated automated virtual accounts for parents.",
            'general_checkin' => "A routine termly check-in to offer school management optimization, 1-click broadsheets, and WhatsApp result delivery.",
        ];

        $reasonText = $reasonDescriptions[$inactivityReason] ?? $reasonDescriptions['general_checkin'];

        $prompt = <<<EOT
You are {$this->config->agent_name}, a friendly, respectful, and highly consultative Senior Growth Advisor at SchoolProfit (GradeQuest).
Write a short, engaging, and high-converting WhatsApp message to {$ownerName}, the owner/head of {$schoolName}.

Context & Business Rules:
- Inactivity reason: {$reasonText}
- School Name: {$schoolName}
- Pricing Model: SchoolProfit uses ONLY a small per-student fee (Basic Results/Portal: ₦{$basicPrice}/student, Full CBT Suite: ₦{$cbtPrice}/student).
- Zero-Cost Advantage: Schools can pass this small platform fee to parents at the point of fee payment (meaning ZERO cost: ₦0.00 to the school) or have it deducted from collected school fees. NO expensive monthly subscriptions!
- Value Proposition: Eliminate unpaid school fee debts with automated parent virtual accounts, compile 100% accurate term broadsheets & WAEC-standard report cards in 1 click, and run offline CBT exams.
- Call to Action: Invite them to book a quick 10-minute live demo/onboarding walkthrough at {$bookingUrl} or reply directly on WhatsApp so our team can import their student list for free.
- Tone: Professional, warm Nigerian educational context, respectful, concise (under 120 words), with clean WhatsApp formatting (bold keywords, bullet points, polite greeting).
- Output ONLY the WhatsApp message text without quotes or meta-commentary.
EOT;

        if (! $apiKey) {
            return "Good day {$ownerName}, trust {$schoolName} is having a fruitful academic term!

This is {$this->config->agent_name} from *SchoolProfit*.

We noticed your school portal is ready but your student list isn't uploaded yet. With the new term preparations underway, we'd love to help your school:
• *Zero Cost to School (₦0.00)*: Pass the tiny per-student fee to parents or deduct from fee collections (no subscription fees!).
• *Eliminate School Fee Debts*: Automated parent virtual accounts with instant clearance passes.
• *1-Click Broadsheets & Report Cards*: Instant error-free result compilation.

Would you like our onboarding team to import your student roster for free today? 
📅 Book a quick 10-min walkthrough: {$bookingUrl}
Or simply reply directly to this WhatsApp message!

Warm regards,
*SchoolProfit Growth Team*";
        }

        try {
            $response = Http::withToken($apiKey)
                ->connectTimeout(10)
                ->timeout(30)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $this->config->model ?: 'gpt-4o-mini',
                    'messages' => [
                        ['role' => 'system', 'content' => 'You write persuasive, warm B2B WhatsApp messages to Nigerian school owners and proprietors.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'temperature' => 0.75,
                    'max_tokens' => 350,
                ]);

            if ($response->successful()) {
                return trim($response->json('choices.0.message.content') ?? '');
            }
        } catch (\Exception $e) {
            Log::warning('AI re-engagement generation fallback used: ' . $e->getMessage());
        }

        return "Good day {$ownerName}, trust {$schoolName} is doing great!

This is {$this->config->agent_name} from *SchoolProfit*.

We are checking in to help {$schoolName} eliminate unpaid school fees and automate your end-of-term broadsheets with zero upfront cost (₦0.00 expense to your school).

Reply to this message to speak with your dedicated onboarding specialist or book a quick live walkthrough here: {$bookingUrl}";
    }

    /**
     * Scan database for dormant/inactive schools and stage them for WhatsApp outreach.
     */
    public function scanInactiveSchools(int $thresholdDays = 14): array
    {
        $cutoffDate = Carbon::now()->subDays($thresholdDays);

        $schools = SchoolSetting::with(['users' => function ($q) {
            $q->whereIn('role', ['Admin', 'Super-Admin', 'admin', 'super-admin'])->orderBy('id', 'asc');
        }])->get();

        $stagedOutreach = [];

        foreach ($schools as $school) {
            $studentsCount = User::where('school_id', $school->id)->where('role', 'Student')->count();
            
            // Retrieve admin/owner safely
            $owner = ($school->relationLoaded('users') && $school->users->isNotEmpty()) 
                ? $school->users->first() 
                : User::where('school_id', $school->id)->whereIn('role', ['Admin', 'Super-Admin', 'admin', 'super-admin'])->first();
                
            $phone = $school->phone ?: $school->phone_number ?: ($owner->phone ?? $owner->phone_number ?? null);

            if (! $phone) {
                continue;
            }

            $inactivityReason = null;

            if ($studentsCount === 0 && $school->created_at <= $cutoffDate) {
                $inactivityReason = '0_students_uploaded';
            } elseif ($school->updated_at <= $cutoffDate) {
                $inactivityReason = 'no_login_30_days';
            }

            if (! $inactivityReason) {
                continue;
            }

            // Check if outreach was already sent in the last 14 days
            $existingLog = AiSalesOutreachLog::where('school_id', $school->id)
                ->where('created_at', '>=', Carbon::now()->subDays(14))
                ->first();

            if ($existingLog) {
                continue;
            }

            // Generate customized AI pitch
            $generatedMessage = $this->generateReengagementMessage($school, $owner, $inactivityReason);

            $log = AiSalesOutreachLog::create([
                'school_id' => $school->id,
                'user_id' => $owner->id ?? null,
                'school_name' => $school->school_name ?: ('School #' . $school->id),
                'contact_person' => $owner ? "{$owner->firstname} {$owner->surname}" : 'School Administrator',
                'phone_number' => $phone,
                'inactivity_reason' => $inactivityReason,
                'generated_message' => $generatedMessage,
                'status' => 'staged',
                'delivery_channel' => 'whatsapp',
            ]);

            $stagedOutreach[] = [
                'log_id' => $log->id,
                'school_id' => $school->id,
                'school_name' => $school->school_name ?: ('School #' . $school->id),
                'contact_person' => $log->contact_person,
                'phone_number' => $phone,
                'inactivity_reason' => $inactivityReason,
                'message' => $generatedMessage,
                'whatsapp_url' => $this->buildWhatsAppUrl($phone, $generatedMessage),
            ];
        }

        return $stagedOutreach;
    }

    /**
     * Generate direct wa.me link with prefilled copy.
     */
    public function buildWhatsAppUrl(string $phone, string $message): string
    {
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        if (str_starts_with($cleanPhone, '0')) {
            $cleanPhone = '234' . substr($cleanPhone, 1);
        } elseif (! str_starts_with($cleanPhone, '234') && strlen($cleanPhone) === 10) {
            $cleanPhone = '234' . $cleanPhone;
        }

        return "https://wa.me/{$cleanPhone}?text=" . rawurlencode($message);
    }

    /**
     * Generate intelligent dynamic fallback reply with real-time DB billing rates when OpenAI is unavailable.
     */
    public function generateFallbackReply(array $messages, array $context = []): string
    {
        $billingPolicy = GradequestBillingPolicy::first();
        $basicPrice = $billingPolicy ? number_format((float) ($billingPolicy->basic_tier_price_per_student ?? 300), 0) : '300';
        $cbtPrice = $billingPolicy ? number_format((float) ($billingPolicy->standard_cbt_tier_price_per_student ?? 500), 0) : '500';
        $platformFee = $billingPolicy ? number_format((float) ($billingPolicy->platform_fee_per_student ?? 500), 0) : '500';
        $demoUrl = $this->config->demo_booking_url ?: 'https://schoolprofit.ng/book-demo';
        $whatsapp = $this->config->support_whatsapp_number ?: '+2348165748374';

        // Extract last user query
        $lastUserMsg = '';
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') {
                $lastUserMsg = strtolower($messages[$i]['content'] ?? '');
                break;
            }
        }

        if (preg_match('/(pricing|price|cost|how much|package|edition|tier|rate|fee|charge|bill|plan|300|500)/i', $lastUserMsg)) {
            return "SchoolProfit operates on a transparent, purely per-student fee model with **₦0 upfront software license fees** and two flexible editions:\n\n"
                . "1. **Basic Result Edition (₦{$basicPrice} per student / term)**:\n"
                . "   • 1-Click Automated Broadsheets & WAEC/NECO format Report Cards\n"
                . "   • Automated Grading, Cumulative Averages & AI Teacher Remarks\n"
                . "   • Dedicated Student Virtual Accounts for direct tuition collections\n"
                . "   • Complete Bursary Accounting & Defaulter Tracking\n\n"
                . "2. **Standard CBT & AI Edition (₦{$cbtPrice} per student / term)**:\n"
                . "   • **Everything in the Basic Package** +\n"
                . "   • Full Offline & Online Computer-Based Testing (CBT) Examination suite\n"
                . "   • Automated WhatsApp Broadsheet & Report Card Delivery to Parents\n"
                . "   • AI Lesson Plan & Scheme of Work Generators\n\n"
                . "💡 **Zero-Cost Advantage (₦0.00 Expense to School)**:\n"
                . "Your school can pass this small platform fee to parents on their termly fee payment invoices, meaning the platform costs your school **₦0.00** from your pocket!\n\n"
                . "📅 Would you like to schedule a 15-minute live screen walkthrough for your school? You can book at [schoolprofit.ng/book-demo]({$demoUrl}) or message our growth desk on WhatsApp ({$whatsapp})!";
        }

        if (preg_match('/(zero|0\.00|parent|pass|recover|free)/i', $lastUserMsg)) {
            return "With SchoolProfit's **Zero-Cost (₦0.00) Model**, your school pays nothing out-of-pocket for full digital portal management:\n\n"
                . "• **How It Works**: Every enrolled student receives a dedicated Wema Bank Virtual Account. When parents pay termly tuition fees, the small platform fee (₦{$basicPrice} Basic or ₦{$cbtPrice} Full CBT) is added to the parent's invoice.\n"
                . "• **Instant Settlement**: 100% of your tuition fees settle directly into your school bank account with automated digital payment receipts sent to parents on WhatsApp.\n"
                . "• **Alternative Option**: You can also choose to absorb the fee by having it automatically deducted from school fee collections upon settlement.\n\n"
                . "Would you like to see how this works in a live 15-minute demo? Book here: [schoolprofit.ng/book-demo]({$demoUrl})!";
        }

        if (preg_match('/(broadsheet|report|result|card|grade|waec|domain)/i', $lastUserMsg)) {
            return "SchoolProfit completely eliminates manual report card errors and broadsheet calculation stress:\n\n"
                . "• **1-Click Generation**: Teachers enter Continuous Assessment (CA) and exam scores once, and the system instantly compiles 100% accurate master broadsheets.\n"
                . "• **WAEC/NECO Standard**: Beautiful, printable PDF report cards with student photos, grading keys, class positions, and automated AI teacher remarks.\n"
                . "• **Pricing**: Only **₦{$basicPrice}/student/term** for Basic or **₦{$cbtPrice}/student/term** for Full CBT & AI delivery.\n\n"
                . "Ready to eliminate end-of-term stress? Book an onboarding session at [schoolprofit.ng/book-demo]({$demoUrl})!";
        }

        if (preg_match('/(cbt|exam|offline|test|mock|question)/i', $lastUserMsg)) {
            return "Our **Full CBT & AI Edition (₦{$cbtPrice}/student/term)** brings state-of-the-art testing to your school:\n\n"
                . "• **Offline-First Engine**: Conduct Computer-Based Tests in your lab with **zero internet reliance** during exam sessions.\n"
                . "• **Instant Auto-Grading**: Real-time scoring and student performance analytics.\n"
                . "• **AI Question Generator**: Generate curriculum-compliant exam questions and lesson plans in seconds.\n\n"
                . "Let us set up a test CBT simulation for your school. Book a demo at [schoolprofit.ng/book-demo]({$demoUrl})!";
        }

        if (preg_match('/(demo|onboard|start|book|signup|sign up|meeting|checklist|prepare)/i', $lastUserMsg)) {
            return "Getting started with SchoolProfit is fast and personalized:\n\n"
                . "1. **Book a Walkthrough**: Schedule a 15-minute live screen demo at [schoolprofit.ng/book-demo]({$demoUrl}).\n"
                . "2. **What to Prepare**:\n"
                . "   • Estimated student count and class list.\n"
                . "   • Current tuition fee structure.\n"
                . "   • Invite your Bursar or Academic Coordinator to join.\n"
                . "3. **Free Data Migration**: Our onboarding team will import your student roster and configure your school portal for free!\n\n"
                . "Feel free to message our support desk directly on WhatsApp: {$whatsapp}!";
        }

        return "Hello! I am Sarah, your Senior Growth Advisor at SchoolProfit.\n\n"
            . "We help Nigerian private schools **eliminate unpaid fee debts**, generate **1-click error-free broadsheets**, and run **offline CBT exams** at **zero cost (₦0.00)** to the school:\n\n"
            . "• **Basic Result Edition**: ₦{$basicPrice} per student / term\n"
            . "• **Standard CBT & AI Edition**: ₦{$cbtPrice} per student / term\n"
            . "• **Zero School Cost Option**: Pass the small per-student fee to parents during tuition payments!\n\n"
            . "How can I help your school today? You can also book a live walkthrough at [schoolprofit.ng/book-demo]({$demoUrl}) or chat with us on WhatsApp ({$whatsapp}).";
    }

    /**
     * Build comprehensive system prompt containing SchoolProfit's vision, benefits & links.
     */
    protected function buildSystemPrompt(array $context = []): string
    {
        $agentName = $this->config->agent_name ?: 'Sarah - SchoolProfit Growth Consultant';
        $demoUrl = $this->config->demo_booking_url ?: 'https://schoolprofit.ng/book-demo';
        $whatsappNumber = $this->config->support_whatsapp_number ?: '+2348000000000';
        $customInstructions = $this->config->custom_instructions ?: '';

        // Dynamically fetch active platform billing rates from GradequestBillingPolicy
        $billingPolicy = GradequestBillingPolicy::first();
        $basicTierPrice = $billingPolicy ? number_format((float) ($billingPolicy->basic_tier_price_per_student ?? 300), 0) : '300';
        $cbtTierPrice = $billingPolicy ? number_format((float) ($billingPolicy->standard_cbt_tier_price_per_student ?? 500), 0) : '500';
        $platformFee = $billingPolicy ? number_format((float) ($billingPolicy->platform_fee_per_student ?? 500), 0) : '500';

        return <<<PROMPT
You are {$agentName}, the Lead AI Sales & Education Growth Consultant for SchoolProfit (GradeQuest platform).

══════════════════════════════════════════════════════════════════════════════
MANDATORY DYNAMIC PRICING DIRECTIVE (STRICT ENFORCEMENT):
══════════════════════════════════════════════════════════════════════════════
The active platform billing rates configured in the database are:
• Basic Result Edition: ₦{$basicTierPrice} per student / term
• Standard CBT & AI Edition: ₦{$cbtTierPrice} per student / term
• Platform Fee Per Student: ₦{$platformFee} per student

YOU MUST STRICTLY USE THESE EXACT NUMBERS (₦{$basicTierPrice} for Basic and ₦{$cbtTierPrice} for Full CBT). NEVER quote outdated prices (like 300 or 500 unless those match the numbers above). Always be 100% accurate with these live database prices.

══════════════════════════════════════════════════════════════════════════════
OUR VISION & PURPOSE (WHY SCHOOLPROFIT WAS CREATED):
══════════════════════════════════════════════════════════════════════════════
1. Eradicate Unpaid School Fees & Bad Debt:
   - Private school proprietors suffer severe cashflow collapse and millions in unpaid fees from defaulting parents.
   - SchoolProfit solves this completely: Every enrolled student gets a dedicated, automated Wema Bank Virtual Account. Parents make payments directly, receive instant WhatsApp digital receipts, and cannot take exams or access report cards without an automated digital Clearance Gate Pass.
2. End End-of-Term Broadsheet & Report Card Nightmares:
   - Teachers spend weeks manually calculating scores, class positions, averages, and affective/psychomotor domains on paper with errors.
   - SchoolProfit generates 100% accurate term broadsheets, WAEC/NECO format report cards, and automated AI teacher remarks in 1 click.
3. Offline-First CBT & Modern Examination Suites:
   - Schools can conduct Computer-Based Tests without worrying about unstable internet. The offline runner syncs automatically when reconnected.
4. Dedicated School Website & Custom Domain:
   - Every school gets an official modern website (e.g. yourschool.com.ng) with online admissions and student registration.

══════════════════════════════════════════════════════════════════════════════
PRICING ARCHITECTURE (NO SUBSCRIPTION PLANS — PER-STUDENT CHARGE ONLY):
══════════════════════════════════════════════════════════════════════════════
CRITICAL INSTRUCTION: SchoolProfit DOES NOT use fixed monthly or annual subscription packages. 
We operate exclusively on a transparent, purely PER-STUDENT charge on fee payment collections:

1. Basic Package (₦{$basicTierPrice} per student / term):
   - 1-Click Automated Broadsheets & WAEC/NECO format Report Cards
   - Automated Affective & Psychomotor Domains, Automated AI Teacher Remarks
   - School Tuition Fee Management, Payment Tracking & Virtual Accounts
   - Online Admissions Portal & Student/Parent Management
   - Dedicated School Website & Custom Domain Support

2. Full Package with CBT (₦{$cbtTierPrice} per student / term):
   - EVERYTHING in the Basic Package +
   - Full Computer-Based Testing (CBT) Examination Suite (works seamlessly offline & online)
   - Automated WhatsApp Result & BroadSheet Delivery
   - AI Lesson Note & Scheme of Work Generators
   - Advanced CBT Analytics & Auto-Grading

3. Two Flexible Payment Options for the School:
   - OPTION A (Zero-Cost to School - ₦0.00 Expense): The school can pass the small platform fee to parents at the point of school fee payment. The platform fee is added to the parent's invoice, meaning the school spends ZERO NAIRA (₦0.00) from their pocket for complete digital management!
   - OPTION B (Deducted from School Fees): The school can choose to absorb the platform fee, which is automatically deducted from school fees collected via dedicated parent virtual accounts upon settlement.

══════════════════════════════════════════════════════════════════════════════
HOW NEW SCHOOLS GET STARTED & ONBOARDED:
══════════════════════════════════════════════════════════════════════════════
1. Prospect Onboarding Flow:
   - New prospective schools DO NOT register blindly on their own. They must book a live demonstration and personalized onboarding session at: {$demoUrl}
   - During or after the booked session, the dedicated SchoolProfit Onboarding Team personally configures and onboards the school.
   - IMPORTANT NOTE: The URL `/onboarding` is strictly for existing registered schools to complete their portal setup checklist, NOT for initial prospect self-registration. Always direct cold prospects to book at {$demoUrl}.
2. What School Owners Should Prepare for Their Demo / Onboarding:
   - Step 1: Estimated student population and class breakdown (e.g. Creche, Nursery, Primary, JSS, SSS).
   - Step 2: Current tuition fee schedule/structure.
   - Step 3: Have the Bursar, Vice Principal, or Head of Academics join the 15-minute live screen walkthrough.
   - Step 4: Access the demo booking room at: {$demoUrl}.

══════════════════════════════════════════════════════════════════════════════
YOUR PRIMARY GOAL: MAKE SALES & GUIDE PROSPECTS
══════════════════════════════════════════════════════════════════════════════
1. Consultative Selling: Ask open questions about their school size, current fee collection rate, and how they currently compile report cards.
2. Clear Explanations: Articulate the benefits directly addressing their specific pain points (unpaid fees, manual calculation stress, zero-cost to school).
3. Primary Call-to-Actions & Links:
   - Live Demo & Onboarding Booking: {$demoUrl}
   - WhatsApp Support & Growth Desk: {$whatsappNumber}
4. Tone: Confident, encouraging, authoritative, consultative, professional Nigerian education advisor. Always format messages neatly with bold headers and bullet points where appropriate.

{$customInstructions}
PROMPT;
    }

    /**
     * Extract lead information (name, school, phone, students count) from chat history.
     */
    protected function extractLeadMetadata(AiSalesConversation $conversation, array $messages): void
    {
        $allText = '';
        foreach ($messages as $msg) {
            $allText .= ' ' . ($msg['content'] ?? '');
        }

        // Phone extraction
        if (! $conversation->phone_number && preg_match('/(?:(?:\+?234)|0)[789][01]\d{8}/', $allText, $phoneMatch)) {
            $conversation->phone_number = $phoneMatch[0];
        }

        // Email extraction
        if (! $conversation->email && preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $allText, $emailMatch)) {
            $conversation->email = $emailMatch[0];
        }

        // Update status if qualification signals are met
        if ($conversation->phone_number && $conversation->lead_status === 'inquiry') {
            $conversation->lead_status = 'qualified';
        }
    }
}

