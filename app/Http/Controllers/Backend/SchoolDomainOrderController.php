<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\SchoolDomain;
use App\Models\SchoolDomainOrder;
use App\Models\SchoolSetting;
use App\Services\SchoolDomainService;
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
        private SchoolDomainService $domainService
    ) {
        $this->paystackSecretKey = (string) (config('services.paystack.secret') ?: env('PAYSTACK_SECRET_KEY'));
    }

    /**
     * Standard pricing tiers for domain registration & managed hosting.
     */
    public const DOMAIN_PRICING = [
        '.com.ng' => ['price' => 35000.00, 'label' => '.com.ng (Nigeria Commercial/Standard)', 'popular' => true],
        '.sch.ng' => ['price' => 35000.00, 'label' => '.sch.ng (Official Academic)', 'popular' => false],
        '.ng'     => ['price' => 45000.00, 'label' => '.ng (Direct National Pride)', 'popular' => false],
        '.com'    => ['price' => 50000.00, 'label' => '.com (Global Commercial)', 'popular' => true],
        '.org'    => ['price' => 55000.00, 'label' => '.org (Global Organization)', 'popular' => false],
    ];

    /**
     * Check domain pricing & availability.
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

        $results = [];
        foreach (self::DOMAIN_PRICING as $tld => $meta) {
            $candidateDomain = $cleanName . $tld;
            
            // Check if already registered in our system
            $isUsedInSchoolProfit = SchoolDomain::where('domain', $candidateDomain)->exists() ||
                SchoolDomainOrder::where('domain_name', $candidateDomain)->whereIn('status', ['paid', 'active', 'provisioning'])->exists();

            // Lightweight DNS check
            $hasDns = false;
            if (! $isUsedInSchoolProfit) {
                $hasDns = ! empty(@dns_get_record($candidateDomain, DNS_A | DNS_NS));
            }

            $available = ! $isUsedInSchoolProfit && ! $hasDns;

            $results[] = [
                'domain' => $candidateDomain,
                'tld' => $tld,
                'available' => $available,
                'price' => $meta['price'],
                'label' => $meta['label'],
                'popular' => $meta['popular'],
                'includes' => [
                    '1-Year Domain Registration',
                    'High-Speed Managed Hosting',
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

        // Determine TLD and pricing
        $selectedTld = null;
        $unitPrice = 45000.00; // fallback
        foreach (self::DOMAIN_PRICING as $tld => $info) {
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
     * Verify Paystack Domain Purchase Payment.
     */
    public function verifyOrder(Request $request, string $reference): JsonResponse
    {
        $school = $this->resolveSchool($request);
        $orderQuery = SchoolDomainOrder::where('payment_reference', $reference);
        if ($school) {
            $orderQuery->where('school_id', $school->id);
        }
        $order = $orderQuery->firstOrFail();

        if (in_array($order->status, ['paid', 'active', 'provisioning'], true)) {
            return response()->json([
                'status' => true,
                'message' => 'Domain payment already confirmed.',
                'order' => $order,
            ]);
        }

        try {
            $response = Http::withToken($this->paystackSecretKey)
                ->get("https://api.paystack.co/transaction/verify/{$reference}");

            if ($response->successful() && $response->json('status') && ($response->json('data.status') === 'success')) {
                $data = $response->json('data');
                
                $order->update([
                    'status' => 'active',
                    'paystack_transaction_id' => $data['id'] ?? null,
                    'paid_at' => now(),
                    'activated_at' => now(),
                    'expires_at' => now()->addYears($order->duration_years),
                    'dns_configured' => true,
                    'registrar_name' => 'schoolprofit_automated',
                    'nameservers' => ['ns1.schoolprofit.ng', 'ns2.schoolprofit.ng'],
                ]);

                // Automatically register or update SchoolDomain
                $school = SchoolSetting::find($order->school_id);
                if ($school) {
                    $schoolDomain = SchoolDomain::updateOrCreate(
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
                    'message' => 'Congratulations! Payment confirmed and domain configured successfully.',
                    'order' => $order->fresh(),
                ]);
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
        ]);

        $schoolDomain = $this->domainService->register($school, $validated['domain']);
        $instructions = $this->domainService->instructions($schoolDomain);

        return response()->json([
            'status' => true,
            'message' => 'Domain registered for verification. Add the DNS records to complete setup.',
            'domain' => $schoolDomain,
            'instructions' => $instructions,
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
            'pricing' => self::DOMAIN_PRICING,
            'server_ip' => '18.133.82.13',
            'cname_target' => config('domains.cname_target', 'portal.schoolprofit.ng'),
        ]);
    }
}
