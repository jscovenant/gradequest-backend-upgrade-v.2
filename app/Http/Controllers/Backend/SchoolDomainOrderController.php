<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\SchoolDomain;
use App\Models\SchoolDomainOrder;
use App\Models\SchoolSetting;
use App\Services\SchoolDomainService;
use App\Services\WhogohostResellerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SchoolDomainOrderController extends Controller
{
    private string $paystackSecretKey;

    public function __construct(
        private SchoolDomainService $domainService,
        private WhogohostResellerService $whogohostService
    ) {
        $this->paystackSecretKey = (string) (config('services.paystack.secret') ?: env('PAYSTACK_SECRET_KEY'));
    }

    /**
     * Dynamically resolve existing custom domain setup fee configured by Super-Admin.
     */
    public static function getExistingDomainSetupFee(): float
    {
        $policy = \App\Models\GradequestBillingPolicy::first();
        $customPricing = is_array($policy?->domain_pricing) ? $policy->domain_pricing : [];
        if (isset($customPricing['setup_fee']['price']) && is_numeric($customPricing['setup_fee']['price'])) {
            return (float) $customPricing['setup_fee']['price'];
        }
        if (isset($customPricing['setup_fee']) && is_numeric($customPricing['setup_fee'])) {
            return (float) $customPricing['setup_fee'];
        }
        return 10000.00; // Default: ₦10,000 one-time setup & DNS routing fee
    }

    /**
     * Dynamically resolve domain pricing tiers configured by Super-Admin.
     */
    public static function getPricingTiers(): array
    {
        $policy = \App\Models\GradequestBillingPolicy::first();
        $customPricing = is_array($policy?->domain_pricing) ? $policy->domain_pricing : [];

        $tiers = [
            '.com.ng' => ['price' => 35000.00, 'label' => '.com.ng (Nigeria Commercial/Standard)', 'popular' => true],
            '.sch.ng' => ['price' => 35000.00, 'label' => '.sch.ng (Official Academic)', 'popular' => false],
            '.ng'     => ['price' => 45000.00, 'label' => '.ng (Direct National Pride)', 'popular' => false],
            '.com'    => ['price' => 50000.00, 'label' => '.com (Global Commercial)', 'popular' => true],
            '.org'    => ['price' => 55000.00, 'label' => '.org (Global Organization)', 'popular' => false],
        ];

        foreach ($tiers as $tld => $meta) {
            if (isset($customPricing[$tld]['price']) && is_numeric($customPricing[$tld]['price']) && (float) $customPricing[$tld]['price'] > 0) {
                $tiers[$tld]['price'] = (float) $customPricing[$tld]['price'];
            }
            if (! empty($customPricing[$tld]['label'])) {
                $tiers[$tld]['label'] = (string) $customPricing[$tld]['label'];
            }
        }

        return $tiers;
    }

    /**
     * Check domain pricing & real-time availability via Whogohost Reseller API.
     */
    public function check(Request $request): JsonResponse
    {
        $request->validate([
            'query' => 'required|string|min:3|max:100',
        ]);

        $input = strtolower(trim($request->input('query')));
        $cleanName = preg_replace('/^(https?:\/\/)?(www\.)?/', '', $input);
        $cleanName = preg_replace('/\.[a-z.]+$/', '', $cleanName);
        $cleanName = Str::slug($cleanName);

        if (empty($cleanName)) {
            return response()->json(['message' => 'Please enter a valid school domain name query.'], 422);
        }

        $pricing = self::getPricingTiers();
        $tlds = array_keys($pricing);

        // Perform live lookup via Whogohost Reseller Service
        $availabilityMap = $this->whogohostService->lookup($cleanName, $tlds);

        $results = [];
        foreach ($pricing as $tld => $meta) {
            $candidateDomain = $cleanName . $tld;

            // Check if already registered or in active order in SchoolProfit
            $isUsedInSchoolProfit = SchoolDomain::where('domain', $candidateDomain)->exists() ||
                SchoolDomainOrder::where('domain_name', $candidateDomain)->whereIn('status', ['paid', 'active', 'provisioning', 'provisioning_pending'])->exists();

            $isAvailableViaApi = $availabilityMap[$candidateDomain] ?? null;

            if ($isUsedInSchoolProfit) {
                $available = false;
            } elseif ($isAvailableViaApi !== null) {
                $available = (bool) $isAvailableViaApi;
            } else {
                // Fallback lightweight DNS check
                $hasDns = ! empty(@checkdnsrr($candidateDomain, 'A') || @checkdnsrr($candidateDomain, 'NS'));
                $available = ! $hasDns;
            }

            $results[] = [
                'domain' => $candidateDomain,
                'tld' => $tld,
                'available' => $available,
                'price' => $meta['price'],
                'label' => $meta['label'],
                'popular' => $meta['popular'],
                'includes' => [
                    '1-Year Official Domain Registration',
                    'High-Speed Managed Cloud Hosting',
                    'Automated Let\'s Encrypt SSL (HTTPS)',
                    'Custom School Website & Public Pages',
                    'Custom Branded Portal Login',
                ],
            ];
        }

        return response()->json([
            'status' => true,
            'query' => $cleanName,
            'suggestions' => $results,
            'server_ip' => '18.133.82.13',
            'cname_target' => config('domains.cname_target', 'portal.schoolprofit.ng'),
        ]);
    }

    /**
     * Resolve school safely.
     */
    protected function resolveSchool(Request $request): ?SchoolSetting
    {
        $user = Auth::user();
        $schoolId = $user->school_id ?? $request->query('school_id') ?? $request->input('school_id');
        if ($schoolId) {
            $school = SchoolSetting::find($schoolId);
            if ($school) {
                return $school;
            }
        }
        if ($user && ($user->role === 'Super-Admin' || (method_exists($user, 'isSuperAdminUser') && $user->isSuperAdminUser()))) {
            return SchoolSetting::first();
        }
        return SchoolSetting::first();
    }

    /**
     * Initiate Domain Purchase Order with Paystack Checkout.
     */
    public function initiateOrder(Request $request): JsonResponse
    {
        $school = $this->resolveSchool($request);
        if (!$school) {
            return response()->json(['status' => false, 'message' => 'Active school profile not found.'], 404);
        }

        $user = Auth::user();

        $validated = $request->validate([
            'domain_name' => 'required|string|max:150',
            'duration_years' => 'nullable|integer|min:1|max:5',
        ]);

        $domainName = strtolower(trim($validated['domain_name']));
        $durationYears = (int) ($validated['duration_years'] ?? 1);

        // Determine TLD and pricing dynamically
        $pricing = self::getPricingTiers();
        $selectedTld = null;
        $unitPrice = 45000.00; // fallback
        foreach ($pricing as $tld => $info) {
            if (str_ends_with($domainName, $tld)) {
                $selectedTld = $tld;
                $unitPrice = $info['price'];
                break;
            }
        }

        if (! $selectedTld) {
            $selectedTld = '.custom';
            $unitPrice = 45000.00;
        }

        $totalAmount = $unitPrice * $durationYears;
        $reference = 'SP_DOM_' . strtoupper(Str::random(14));

        $order = SchoolDomainOrder::create([
            'school_id' => $school->id,
            'domain_name' => $domainName,
            'tld' => $selectedTld,
            'duration_years' => $durationYears,
            'amount' => $totalAmount,
            'payment_gateway' => 'paystack',
            'payment_reference' => $reference,
            'status' => 'pending_payment',
            'meta' => [
                'ordered_by_user_id' => $user?->id ?? null,
                'ordered_by_email' => $user?->email ?? $school->email,
                'school_name' => $school->school_name,
            ],
        ]);

        // Initialize Paystack payment
        if (empty($this->paystackSecretKey)) {
            return response()->json([
                'status' => false,
                'message' => 'Paystack is not configured on this server.',
            ], 500);
        }

        $amountInKobo = (int) round($totalAmount * 100);
        $callbackUrl = config('app.frontend_url', 'https://schoolprofit.ng') . '/admin/school/domain-and-website?reference=' . $reference;

        try {
            $response = Http::withToken($this->paystackSecretKey)->post('https://api.paystack.co/transaction/initialize', [
                'email' => $user?->email ?: ($school->email ?: 'admin@' . $domainName),
                'amount' => $amountInKobo,
                'reference' => $reference,
                'callback_url' => $callbackUrl,
                'metadata' => [
                    'order_type' => 'domain_purchase',
                    'order_id' => $order->id,
                    'school_id' => $school->id,
                    'domain_name' => $domainName,
                    'duration_years' => $durationYears,
                ],
            ]);

            if ($response->successful() && $response->json('status')) {
                $paystackData = $response->json('data');
                return response()->json([
                    'status' => true,
                    'message' => 'Paystack checkout initialized successfully.',
                    'order' => $order,
                    'authorization_url' => $paystackData['authorization_url'],
                    'access_code' => $paystackData['access_code'],
                    'reference' => $reference,
                ]);
            }

            Log::error('Paystack Domain Order Initialization Failed', ['response' => $response->body()]);
            return response()->json([
                'status' => false,
                'message' => $response->json('message') ?: 'Unable to initialize Paystack checkout.',
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Paystack Domain Order Exception: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Payment service temporarily unavailable. Please try again.',
            ], 500);
        }
    }

    /**
     * Verify Paystack Domain Purchase / Connection Payment.
     */
    public function verifyOrder(Request $request, string $reference): JsonResponse
    {
        $school = $this->resolveSchool($request);
        $orderQuery = SchoolDomainOrder::where('payment_reference', $reference);
        if ($school) {
            $orderQuery->where('school_id', $school->id);
        }
        $order = $orderQuery->firstOrFail();

        if (in_array($order->status, ['active'], true)) {
            return response()->json([
                'status' => true,
                'message' => 'Domain payment confirmed and active.',
                'order' => $order,
            ]);
        }

        try {
            $response = Http::withToken($this->paystackSecretKey)
                ->get("https://api.paystack.co/transaction/verify/{$reference}");

            if ($response->successful() && $response->json('status') && ($response->json('data.status') === 'success')) {
                $data = $response->json('data');
                $user = Auth::user();
                $orderType = $data['metadata']['order_type'] ?? ($order->meta['order_type'] ?? 'domain_purchase');

                if ($orderType === 'connect_existing') {
                    $order->update([
                        'status' => 'active',
                        'paystack_transaction_id' => $data['id'] ?? $order->paystack_transaction_id,
                        'paid_at' => now(),
                        'activated_at' => now(),
                    ]);

                    $targetSchool = $school ?: SchoolSetting::find($order->school_id);
                    $schoolDomain = $this->domainService->register($targetSchool, $order->domain_name);
                    $instructions = $this->domainService->instructions($schoolDomain);

                    return response()->json([
                        'status' => true,
                        'message' => 'Domain setup fee confirmed! Please configure your DNS records to complete setup.',
                        'order' => $order->fresh(),
                        'domain' => $schoolDomain,
                        'instructions' => $instructions,
                    ]);
                }

                return $this->executeDomainProvisioning($order, $school, $data, $user);
            }

            return response()->json([
                'status' => false,
                'message' => 'Payment has not been completed on Paystack.',
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Paystack Domain Order Verification Error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Verification failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Execute domain provisioning via Whogohost Reseller API.
     */
    public function executeDomainProvisioning(SchoolDomainOrder $order, ?SchoolSetting $school, array $paystackData, $user = null): JsonResponse
    {
        $school = $school ?: SchoolSetting::find($order->school_id);
        $nameservers = config('services.whogohost.nameservers', ['ns1.schoolprofit.ng', 'ns2.schoolprofit.ng']);

        // Build contact details for domain registration
        $nameParts = explode(' ', (string) ($user?->name ?: 'School Administrator'));
        $firstname = $nameParts[0] ?? 'School';
        $lastname = $nameParts[1] ?? 'Admin';

        $contact = [
            'firstname' => $firstname,
            'lastname' => $lastname,
            'company' => $school?->school_name ?: 'SchoolProfit Education',
            'email' => $user?->email ?: ($school?->email ?: 'admin@' . $order->domain_name),
            'address1' => $school?->address ?: '12 Allen Avenue, Ikeja',
            'city' => $school?->city ?: 'Ikeja',
            'state' => $school?->state ?: 'Lagos',
            'phonenumber' => $school?->phone_number ?: '+234.8030000000',
        ];

        // Call Whogohost Reseller API
        $regResult = $this->whogohostService->registerDomain(
            $order->domain_name,
            $order->duration_years,
            $contact,
            $nameservers
        );

        $isAlreadyRegistered = false;
        $errMsg = strtolower((string) ($regResult['message'] ?? ''));
        if (str_contains($errMsg, 'registered before') || str_contains($errMsg, 'already registered') || str_contains($errMsg, 'same domain again')) {
            $isAlreadyRegistered = true;
        } else {
            $domainInfo = $this->whogohostService->getDomainInformation($order->domain_name);
            if (!empty($domainInfo['domain']) || !empty($domainInfo['nameservers'])) {
                $isAlreadyRegistered = true;
            }
        }

        if ($regResult['success'] || $isAlreadyRegistered) {
            // Update order to active
            $order->update([
                'status' => 'active',
                'paystack_transaction_id' => $paystackData['id'] ?? $order->paystack_transaction_id,
                'paid_at' => $order->paid_at ?: now(),
                'activated_at' => now(),
                'expires_at' => now()->addYears($order->duration_years ?: 1),
                'dns_configured' => true,
                'registrar_name' => 'whogohost',
                'registrar_order_id' => $regResult['order_id'] ?? ($order->registrar_order_id ?: null),
                'nameservers' => $nameservers,
                'failure_reason' => null,
                'meta' => array_merge($order->meta ?? [], [
                    'registrar_response' => $regResult['raw'] ?? [],
                    'registered_at' => now()->toIso8601String(),
                    'auto_verified_existing' => $isAlreadyRegistered,
                ]),
            ]);

            // Register/activate SchoolDomain and update school setting
            if ($school) {
                SchoolDomain::updateOrCreate(
                    ['domain' => $order->domain_name],
                    [
                        'school_id' => $school->id,
                        'type' => 'custom',
                        'status' => 'active',
                        'verified_at' => now(),
                        'ownership_verified_at' => now(),
                        'routing_verified_at' => now(),
                        'activated_at' => now(),
                        'last_checked_at' => now(),
                        'last_error' => null,
                    ]
                );

                $school->update(['custom_domain' => $order->domain_name]);
            }

            // Attempt SSL & virtual host provisioning
            if (class_exists(\App\Services\DomainSslProvisionerService::class)) {
                try {
                    app(\App\Services\DomainSslProvisionerService::class)->provisionDomainSsl($order->domain_name);
                } catch (\Throwable $e) {
                    Log::warning("Auto SSL provision warning: " . $e->getMessage());
                }
            }

            return response()->json([
                'status' => true,
                'message' => $isAlreadyRegistered
                    ? "Domain {$order->domain_name} was verified as registered on GO54/Whogohost and is now active for this school."
                    : 'Congratulations! Domain registered and configured successfully with SchoolProfit.',
                'order' => $order->fresh(),
            ]);
        }

        // Domain registration was queued or needs funds in Whogohost wallet
        $order->update([
            'status' => 'provisioning_pending',
            'paystack_transaction_id' => $paystackData['id'] ?? $order->paystack_transaction_id,
            'paid_at' => $order->paid_at ?: now(),
            'registrar_name' => 'whogohost',
            'failure_reason' => $regResult['message'] ?? 'Pending automated provisioning.',
            'meta' => array_merge($order->meta ?? [], [
                'provisioning_error' => $regResult['message'] ?? 'Pending automated provisioning.',
                'registrar_raw' => $regResult['raw'] ?? [],
            ]),
        ]);

        // Always link SchoolDomain and custom_domain so the school immediately sees their domain attached
        if ($school) {
            SchoolDomain::updateOrCreate(
                ['domain' => $order->domain_name],
                [
                    'school_id' => $school->id,
                    'type' => 'custom',
                    'status' => 'pending_verification',
                    'verified_at' => now(),
                    'ownership_verified_at' => now(),
                    'last_checked_at' => now(),
                    'last_error' => null,
                ]
            );

            $school->update(['custom_domain' => $order->domain_name]);
        }

        Log::warning("Whogohost Reseller: Domain registration queued for {$order->domain_name} (Order #{$order->id}). Possible low reseller balance.", [
            'reason' => $regResult['message'] ?? null,
            'school' => $school?->school_name,
        ]);

        return response()->json([
            'status' => false,
            'message' => 'Registrar provisioning pending: ' . ($regResult['message'] ?? 'Unable to complete automated provisioning on GO54/Whogohost. Please verify reseller wallet balance.'),
            'failure_reason' => $regResult['message'] ?? null,
            'order' => $order->fresh(),
        ], 422);
    }

    /**
     * Retry Domain Provisioning (Admin / Super-Admin action).
     */
    public function retryProvisioning(Request $request, int $id): JsonResponse
    {
        $order = SchoolDomainOrder::findOrFail($id);
        $school = SchoolSetting::find($order->school_id);
        $user = Auth::user();

        $paystackData = ['id' => $order->paystack_transaction_id];
        return $this->executeDomainProvisioning($order, $school, $paystackData, $user);
    }

    /**
     * Check Whogohost Reseller Balance.
     */
    public function resellerBalance(): JsonResponse
    {
        $balance = $this->whogohostService->getAccountBalance();
        return response()->json($balance);
    }

    /**
     * Connect existing school-owned domain (DNS CNAME / A-Record setup).
     */
    public function connectExistingDomain(Request $request): JsonResponse
    {
        $school = $this->resolveSchool($request);
        if (!$school) {
            return response()->json(['status' => false, 'message' => 'Active school profile not found.'], 404);
        }

        $validated = $request->validate([
            'domain' => 'required|string|max:150',
            'setup_type' => 'nullable|string|in:portal_subdomain,full_website',
        ]);

        $inputDomain = strtolower(trim($validated['domain']));
        $inputDomain = preg_replace('/^(https?:\/\/)?(www\.)?/', '', $inputDomain);
        $inputDomain = preg_replace('/\/.*$/', '', $inputDomain);
        $inputDomain = strtolower(trim($inputDomain, '. '));

        if (empty($inputDomain)) {
            return response()->json(['status' => false, 'message' => 'Please provide a valid domain name.'], 422);
        }

        $setupType = $validated['setup_type'] ?? (substr_count($inputDomain, '.') > 1 && !str_ends_with($inputDomain, '.com.ng') && !str_ends_with($inputDomain, '.sch.ng') && !str_ends_with($inputDomain, '.org.ng') ? 'portal_subdomain' : 'full_website');

        $user = Auth::user();
        $setupFee = self::getExistingDomainSetupFee();

        // If setup fee > 0, initiate Paystack checkout for the connection fee
        if ($setupFee > 0 && !empty($this->paystackSecretKey)) {
            $reference = 'SP_DOM_CONN_' . strtoupper(Str::random(14));
            $order = SchoolDomainOrder::create([
                'school_id' => $school->id,
                'domain_name' => $inputDomain,
                'tld' => '.' . (pathinfo($inputDomain, PATHINFO_EXTENSION) ?: 'custom'),
                'duration_years' => 1,
                'amount' => $setupFee,
                'payment_gateway' => 'paystack',
                'payment_reference' => $reference,
                'status' => 'pending_payment',
                'meta' => [
                    'order_type' => 'connect_existing',
                    'setup_type' => $setupType,
                    'ordered_by_user_id' => $user?->id ?? null,
                    'ordered_by_email' => $user?->email ?? $school->email,
                    'school_name' => $school->school_name,
                ],
            ]);

            $amountInKobo = (int) round($setupFee * 100);
            $callbackUrl = config('app.frontend_url', 'https://schoolprofit.ng') . '/admin/school/domain-and-website?reference=' . $reference;

            try {
                $response = Http::withToken($this->paystackSecretKey)->post('https://api.paystack.co/transaction/initialize', [
                    'email' => $user?->email ?: ($school->email ?: 'admin@' . $inputDomain),
                    'amount' => $amountInKobo,
                    'reference' => $reference,
                    'callback_url' => $callbackUrl,
                    'metadata' => [
                        'order_type' => 'connect_existing',
                        'order_id' => $order->id,
                        'school_id' => $school->id,
                        'domain_name' => $inputDomain,
                        'setup_type' => $setupType,
                    ],
                ]);

                if ($response->successful() && $response->json('status')) {
                    $paystackData = $response->json('data');
                    return response()->json([
                        'status' => true,
                        'requires_payment' => true,
                        'message' => 'Please complete the custom domain connection fee checkout.',
                        'order' => $order,
                        'authorization_url' => $paystackData['authorization_url'],
                        'access_code' => $paystackData['access_code'],
                        'reference' => $reference,
                        'setup_fee' => $setupFee,
                    ]);
                }

                Log::error('Paystack Domain Connection Fee Init Failed', ['response' => $response->body()]);
            } catch (\Throwable $e) {
                Log::error('Paystack Domain Connection Exception: ' . $e->getMessage());
            }
        }

        // If no fee (or fee = 0), register directly
        $schoolDomain = $this->domainService->register($school, $inputDomain);
        $instructions = $this->domainService->instructions($schoolDomain);

        return response()->json([
            'status' => true,
            'requires_payment' => false,
            'message' => 'Domain registered for verification. Add the DNS records to complete setup.',
            'domain' => $schoolDomain,
            'instructions' => $instructions,
        ]);
    }

    /**
     * Live check whether the domain's CNAME or A records have propagated.
     */
    public function verifyDns(Request $request): JsonResponse
    {
        $school = $this->resolveSchool($request);
        if (!$school) {
            return response()->json(['status' => false, 'message' => 'Active school profile not found.'], 404);
        }

        $domainRecord = SchoolDomain::where('school_id', $school->id)->latest()->first();
        $domainName = $request->input('domain') ?: ($domainRecord?->domain ?: $school->custom_domain);

        if (!$domainName) {
            return response()->json(['status' => false, 'message' => 'No custom domain submitted for verification.'], 422);
        }

        $domainName = strtolower(trim($domainName));
        $targetCname = strtolower(trim((string) config('domains.cname_target', 'portal.schoolprofit.ng'), '. '));
        $targetIps = array_unique(array_filter(array_merge(
            ['18.133.82.13'],
            config('domains.target_ips', [])
        )));

        // Check if this domain was purchased directly through SchoolProfit
        $paidOrder = SchoolDomainOrder::where('school_id', $school->id)
            ->where('domain_name', $domainName)
            ->whereNotNull('paid_at')
            ->first();

        $isPlatformPurchased = ! is_null($paidOrder);

        // Perform DNS lookup
        $records = @dns_get_record($domainName, DNS_CNAME | DNS_A | DNS_AAAA) ?: [];
        $foundCnames = [];
        $foundIps = [];
        $isPropagated = false;

        foreach ($records as $rec) {
            if (isset($rec['target'])) {
                $target = strtolower(trim($rec['target'], '. '));
                $foundCnames[] = $target;
                if ($target === $targetCname || $target === 'portal.schoolprofit.ng' || $target === 'domains.schoolprofit.ng' || str_ends_with($target, 'schoolprofit.ng')) {
                    $isPropagated = true;
                }
            }
            if (isset($rec['ip'])) {
                $ip = trim($rec['ip']);
                $foundIps[] = $ip;
                if (in_array($ip, $targetIps, true)) {
                    $isPropagated = true;
                }
            }
        }

        if ($isPropagated) {
            if ($domainRecord) {
                $domainRecord->forceFill([
                    'status' => 'active',
                    'routing_verified_at' => now(),
                    'verified_at' => $domainRecord->verified_at ?: now(),
                    'activated_at' => $domainRecord->activated_at ?: now(),
                    'last_checked_at' => now(),
                    'last_error' => null,
                    'consecutive_health_failures' => 0,
                ])->save();
            }

            $school->update(['custom_domain' => $domainName]);

            // Attempt SSL & virtual host provisioning
            if (class_exists(\App\Services\DomainSslProvisionerService::class)) {
                try {
                    app(\App\Services\DomainSslProvisionerService::class)->provisionDomainSsl($domainName);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning("Auto SSL provision warning: " . $e->getMessage());
                }
            }

            return response()->json([
                'status' => true,
                'verified' => true,
                'is_platform_purchased' => $isPlatformPurchased,
                'domain' => $domainName,
                'message' => $isPlatformPurchased
                    ? "Domain auto-configuration complete! {$domainName} is active, secured with SSL, and live for your school portal."
                    : "DNS verification successful! {$domainName} is properly pointed to SchoolProfit and active.",
                'records' => [
                    'cnames' => $foundCnames,
                    'ips' => $foundIps,
                    'expected_cname' => $targetCname,
                    'expected_ip' => '18.133.82.13',
                ],
            ]);
        }

        if ($isPlatformPurchased) {
            // For in-platform purchases, don't ask for external manual CNAME/A records!
            if ($paidOrder && $paidOrder->status !== 'active') {
                try {
                    $user = \Illuminate\Support\Facades\Auth::user();
                    $this->executeDomainProvisioning($paidOrder, $school, ['id' => $paidOrder->paystack_transaction_id], $user);
                } catch (\Throwable $e) {
                    // registrar provisioning retry
                }
                $paidOrder->refresh();
            }

            if ($paidOrder && $paidOrder->status === 'active') {
                return response()->json([
                    'status' => true,
                    'verified' => true,
                    'is_platform_purchased' => true,
                    'domain' => $domainName,
                    'message' => "Domain auto-configuration complete! {$domainName} is active, secured with SSL, and live for your school portal.",
                    'records' => [
                        'detected_cnames' => $foundCnames,
                        'detected_ips' => $foundIps,
                        'expected_cname' => $targetCname,
                        'expected_ip' => '18.133.82.13',
                    ],
                ]);
            }

            return response()->json([
                'status' => true,
                'verified' => false,
                'is_platform_purchased' => true,
                'domain' => $domainName,
                'message' => "This domain was purchased directly on SchoolProfit. All DNS routing, nameservers, and SSL are configured automatically by SchoolProfit — no manual action is required. Propagation typically completes within a few minutes.",
                'records' => [
                    'detected_cnames' => $foundCnames,
                    'detected_ips' => $foundIps,
                    'expected_cname' => $targetCname,
                    'expected_ip' => '18.133.82.13',
                ],
            ]);
        }

        return response()->json([
            'status' => true,
            'verified' => false,
            'is_platform_purchased' => false,
            'domain' => $domainName,
            'message' => "DNS records not detected yet for {$domainName}. Please ensure you have added the CNAME or A record in your external domain registrar and allow 5-15 minutes for DNS propagation.",
            'records' => [
                'detected_cnames' => $foundCnames,
                'detected_ips' => $foundIps,
                'expected_cname' => $targetCname,
                'expected_ip' => '18.133.82.13',
            ],
        ]);
    }

    /**
     * Get current school custom domain status & history.
     */
    public function status(Request $request): JsonResponse
    {
        $school = $this->resolveSchool($request);
        if (!$school) {
            return response()->json(['status' => false, 'message' => 'Active school profile not found.'], 404);
        }

        $activeDomain = SchoolDomain::where('school_id', $school->id)->latest()->first();
        $orders = SchoolDomainOrder::where('school_id', $school->id)->latest()->get();

        $currentDomain = $school->custom_domain ?: ($activeDomain?->domain ?? null);

        $paidOrder = SchoolDomainOrder::where('school_id', $school->id)
            ->where(function ($q) use ($currentDomain) {
                if ($currentDomain) {
                    $q->where('domain_name', $currentDomain);
                }
            })
            ->whereNotNull('paid_at')
            ->first();

        $isPlatformPurchased = ! is_null($paidOrder);

        $instructions = null;
        if ($activeDomain && ! $isPlatformPurchased) {
            $instructions = $this->domainService->instructions($activeDomain);
        }

        return response()->json([
            'status' => true,
            'school' => [
                'id' => $school->id,
                'school_name' => $school->school_name,
                'custom_domain' => $school->custom_domain,
                'subdomain' => $school->school_subdomain,
            ],
            'active_domain' => $activeDomain,
            'is_platform_purchased' => $isPlatformPurchased,
            'auto_configured' => $isPlatformPurchased,
            'instructions' => $instructions,
            'orders' => $orders,
            'pricing' => self::getPricingTiers(),
            'setup_fee' => self::getExistingDomainSetupFee(),
            'server_ip' => '18.133.82.13',
            'cname_target' => config('domains.cname_target', 'portal.schoolprofit.ng'),
        ]);
    }

    /**
     * Remove / Disconnect custom domain from the school.
     */
    public function removeDomain(Request $request): JsonResponse
    {
        $school = $this->resolveSchool($request);
        if (!$school) {
            return response()->json(['status' => false, 'message' => 'Active school profile not found.'], 404);
        }

        $domainName = $school->custom_domain ?: SchoolDomain::where('school_id', $school->id)->value('domain');

        // Clear custom domain on school profile
        $school->update(['custom_domain' => null]);

        // Remove SchoolDomain records associated with this school
        SchoolDomain::where('school_id', $school->id)->delete();

        return response()->json([
            'status' => true,
            'message' => $domainName
                ? "Custom domain '{$domainName}' has been disconnected from your school."
                : 'Custom domain removed successfully.',
            'school' => [
                'id' => $school->id,
                'school_name' => $school->school_name,
                'custom_domain' => null,
                'subdomain' => $school->school_subdomain,
            ],
        ]);
    }

    /**
     * Super-Admin: Get all domain orders across all schools and live reseller wallet credits.
     */
    public function superAdminIndex(Request $request): JsonResponse
    {
        $credits = $this->whogohostService->getCredits();

        $query = SchoolDomainOrder::query()->latest();

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('domain_name', 'LIKE', "%{$search}%")
                  ->orWhere('payment_reference', 'LIKE', "%{$search}%");
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $orders = $query->paginate(30);

        // Attach school name and info to each order
        $orders->getCollection()->transform(function ($order) {
            $school = SchoolSetting::find($order->school_id);
            $order->school_name = $school?->school_name ?: ($order->meta['school_name'] ?? 'School #' . $order->school_id);
            $order->school_email = $school?->email ?: ($order->meta['ordered_by_email'] ?? null);
            return $order;
        });

        return response()->json([
            'status' => true,
            'reseller_credits' => $credits,
            'orders' => $orders,
        ]);
    }

    /**
     * Super-Admin: Manually mark an order as active if registered directly on GO54/registrar.
     */
    public function superAdminMarkActive(Request $request, int $id): JsonResponse
    {
        $order = SchoolDomainOrder::findOrFail($id);
        $school = SchoolSetting::find($order->school_id);

        $order->update([
            'status' => 'active',
            'activated_at' => now(),
            'expires_at' => now()->addYears($order->duration_years ?: 1),
            'dns_configured' => true,
            'failure_reason' => null,
            'meta' => array_merge($order->meta ?? [], [
                'manual_activation_by_superadmin' => true,
                'activated_at' => now()->toIso8601String(),
            ]),
        ]);

        if ($school) {
            SchoolDomain::updateOrCreate(
                ['domain' => $order->domain_name],
                [
                    'school_id' => $school->id,
                    'type' => 'custom',
                    'status' => 'active',
                    'verified_at' => now(),
                    'ownership_verified_at' => now(),
                    'routing_verified_at' => now(),
                    'activated_at' => now(),
                    'last_checked_at' => now(),
                ]
            );

            $school->update(['custom_domain' => $order->domain_name]);
        }

        // Try SSL provisioning
        if (class_exists(\App\Services\DomainSslProvisionerService::class)) {
            try {
                app(\App\Services\DomainSslProvisionerService::class)->provisionDomainSsl($order->domain_name);
            } catch (\Throwable $e) {
                // ignore
            }
        }

        return response()->json([
            'status' => true,
            'message' => "Domain {$order->domain_name} has been marked as Active and linked to the school.",
            'order' => $order->fresh(),
        ]);
    }
}
