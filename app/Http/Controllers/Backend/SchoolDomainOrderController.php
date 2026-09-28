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

        if ($regResult['success']) {
            // Update order to active
            $order->update([
                'status' => 'active',
                'paystack_transaction_id' => $paystackData['id'] ?? $order->paystack_transaction_id,
                'paid_at' => $order->paid_at ?: now(),
                'activated_at' => now(),
                'expires_at' => now()->addYears($order->duration_years),
                'dns_configured' => true,
                'registrar_name' => 'whogohost',
                'registrar_order_id' => $regResult['order_id'] ?? null,
                'nameservers' => $nameservers,
                'failure_reason' => null,
                'meta' => array_merge($order->meta ?? [], [
                    'registrar_response' => $regResult['raw'] ?? [],
                    'registered_at' => now()->toIso8601String(),
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

            return response()->json([
                'status' => true,
                'message' => 'Congratulations! Domain registered and configured successfully with SchoolProfit.',
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

        return response()->json([
            'status' => true,
            'message' => 'Payment received successfully! Your domain registration has been queued and is being provisioned automatically.',
            'order' => $order->fresh(),
        ]);
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

            return response()->json([
                'status' => true,
                'verified' => true,
                'domain' => $domainName,
                'message' => "DNS verification successful! {$domainName} is properly pointed to SchoolProfit and active.",
                'records' => [
                    'cnames' => $foundCnames,
                    'ips' => $foundIps,
                    'expected_cname' => $targetCname,
                    'expected_ip' => '18.133.82.13',
                ],
            ]);
        }

        return response()->json([
            'status' => true,
            'verified' => false,
            'domain' => $domainName,
            'message' => "DNS records not detected yet for {$domainName}. Please ensure you have added the CNAME or A record in your domain registrar and allow 5-15 minutes for DNS propagation.",
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

        $instructions = null;
        if ($activeDomain) {
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
}
