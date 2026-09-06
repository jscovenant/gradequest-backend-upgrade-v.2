<?php

namespace App\Services;

use App\Models\SchoolBankAccount;
use App\Models\SchoolSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MonnifyService
{
    private string $apiKey;
    private string $secretKey;
    private string $contractCode;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey = (string) config('services.monnify.api_key', env('MONNIFY_API_KEY', 'MK_TEST_CWU5N946WH'));
        $this->secretKey = (string) config('services.monnify.secret_key', env('MONNIFY_SECRET_KEY', 'J7RR1GJEWV01RRP4YQ3GTH0487AZ80KX'));
        $this->contractCode = (string) config('services.monnify.contract_code', env('MONNIFY_CONTRACT_CODE', '6684134658'));
        $this->baseUrl = rtrim((string) config('services.monnify.base_url', env('MONNIFY_BASE_URL', 'https://sandbox.monnify.com')), '/');
    }

    /**
     * Get or generate a cached Monnify JWT access token.
     */
    public function getAccessToken(): string
    {
        return Cache::remember('monnify_access_token', 3000, function () {
            $authString = base64_encode("{$this->apiKey}:{$this->secretKey}");

            $response = Http::withHeaders([
                'Authorization' => "Basic {$authString}",
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/api/v1/auth/login");

            if (! $response->successful() || ! $response->json('requestSuccessful')) {
                $errorMsg = $response->json('responseMessage') ?? 'Failed to authenticate with Monnify.';
                Log::error('Monnify Auth Error', ['status' => $response->status(), 'body' => $response->body()]);
                throw new RuntimeException("Monnify authentication failed: {$errorMsg}");
            }

            $token = $response->json('responseBody.accessToken');
            if (! $token) {
                throw new RuntimeException('Monnify did not return a valid access token.');
            }

            return $token;
        });
    }

    /**
     * Get list of commercial banks supported by Monnify.
     */
    public function getBanks(): array
    {
        $token = $this->getAccessToken();

        return Cache::remember('monnify_banks_list', 86400, function () use ($token) {
            $response = Http::withToken($token)
                ->get("{$this->baseUrl}/api/v1/banks");

            if (! $response->successful() || ! $response->json('requestSuccessful')) {
                Log::warning('Could not fetch Monnify banks', ['body' => $response->body()]);
                return [];
            }

            return $response->json('responseBody') ?: [];
        });
    }

    /**
     * Resolve destination bank code to match Monnify's bank code dictionary.
     */
    public function resolveMonnifyBankCode(string $bankCode, ?string $bankName = null): string
    {
        $banks = $this->getBanks();
        $cleanCode = trim($bankCode);

        // 1. Direct code match
        foreach ($banks as $b) {
            if (($b['code'] ?? '') === $cleanCode) {
                return $cleanCode;
            }
        }

        // 2. Common aliases between Paystack and Monnify
        $knownAliases = [
            '999991' => '100033', // PalmPay
            '100033' => '100033',
            '999992' => '999992', // OPay
            '090267' => '090267', // Kuda
            '50211'  => '50211',
            '011'    => '011',    // First Bank
            '058'    => '058',    // GTBank
            '057'    => '057',    // Zenith
            '033'    => '033',    // UBA
            '044'    => '044',    // Access
        ];

        if (isset($knownAliases[$cleanCode])) {
            return $knownAliases[$cleanCode];
        }

        // 3. Match by name if provided
        if ($bankName) {
            $normalizedName = strtolower((string) preg_replace('/[^a-z0-9]/', '', $bankName));
            foreach ($banks as $b) {
                $bNorm = strtolower((string) preg_replace('/[^a-z0-9]/', '', $b['name'] ?? ''));
                if ($bNorm && (str_contains($bNorm, $normalizedName) || str_contains($normalizedName, $bNorm))) {
                    return (string) ($b['code'] ?? $cleanCode);
                }
            }
        }

        return $cleanCode;
    }

    /**
     * Create or update a Monnify subaccount for a school.
     * This registers the school's bank account with Monnify for instant split settlement.
     */
    public function createOrUpdateSubaccount(
        int $schoolId,
        string $accountNumber,
        string $bankCode,
        string $accountName,
        ?string $email = null,
        float $splitPercentage = 100.0
    ): string {
        $token = $this->getAccessToken();
        $school = SchoolSetting::find($schoolId);
        $schoolEmail = $email ?: ($school?->email ?: "school{$schoolId}@schoolprofit.ng");
        $schoolName = $accountName ?: ($school?->school_name ?: "School #{$schoolId}");
        $resolvedBankCode = $this->resolveMonnifyBankCode($bankCode, $accountName);

        $payload = [
            [
                'currencyCode' => 'NGN',
                'accountNumber' => trim($accountNumber),
                'bankCode' => $resolvedBankCode,
                'name' => mb_substr(trim($schoolName), 0, 100),
                'email' => trim($schoolEmail),
                'defaultSplitPercentage' => (float) max(0, min(100, $splitPercentage)),
            ],
        ];

        Log::info('Creating Monnify subaccount', ['school_id' => $schoolId, 'payload' => $payload]);

        $response = Http::withToken($token)
            ->post("{$this->baseUrl}/api/v1/sub-accounts", $payload);

        if (! $response->successful() || ! $response->json('requestSuccessful')) {
            $errorMsg = $response->json('responseMessage') ?? 'Failed to register subaccount on Monnify.';
            Log::error('Monnify Subaccount Creation Error', [
                'status' => $response->status(),
                'body' => $response->body(),
                'payload' => $payload,
            ]);
            throw new RuntimeException("Monnify Subaccount Error: {$errorMsg}");
        }

        $body = $response->json('responseBody');
        $first = is_array($body) && isset($body[0]) ? $body[0] : null;
        $subaccountCode = $first['subAccountCode'] ?? null;

        if (! $subaccountCode) {
            throw new RuntimeException('Monnify created the subaccount but did not return a subAccountCode.');
        }

        return $subaccountCode;
    }

    /**
     * Initialize a transaction with Monnify.
     * Generates a checkoutUrl where parents can pay via Instant Bank Transfer, USSD, or Card.
     */
    public function initializeTransaction(array $params): array
    {
        $token = $this->getAccessToken();

        $amount = round((float) ($params['amount'] ?? 0), 2);
        $paymentReference = (string) ($params['payment_reference'] ?? ('gq_mon_' . uniqid()));
        $customerName = trim((string) ($params['customer_name'] ?? 'Parent / Sponsor'));
        $customerEmail = trim((string) ($params['customer_email'] ?? 'parent@schoolprofit.ng'));
        $paymentDescription = (string) ($params['payment_description'] ?? 'School Fee Payment');
        $redirectUrl = (string) ($params['redirect_url'] ?? url('/pay-fees'));

        $body = [
            'amount' => $amount,
            'customerName' => $customerName,
            'customerEmail' => $customerEmail,
            'paymentReference' => $paymentReference,
            'paymentDescription' => $paymentDescription,
            'currencyCode' => 'NGN',
            'contractCode' => $this->contractCode,
            'redirectUrl' => $redirectUrl,
            'paymentMethods' => ['CARD', 'ACCOUNT_TRANSFER', 'USSD', 'PHONE_NUMBER'],
        ];

        // Attach income split configuration if subaccount code provided
        if (! empty($params['subaccount_code'])) {
            $body['incomeSplitConfig'] = [
                [
                    'subAccountCode' => $params['subaccount_code'],
                    'splitPercentage' => (float) ($params['split_percentage'] ?? 100),
                    'feePercentage' => 0,
                    'feeBearer' => false,
                ],
            ];
        }

        Log::info('Initializing Monnify transaction', ['paymentReference' => $paymentReference, 'amount' => $amount]);

        $response = Http::withToken($token)
            ->post("{$this->baseUrl}/api/v1/merchant/transactions/init-transaction", $body);

        if (! $response->successful() || ! $response->json('requestSuccessful')) {
            $errorMsg = $response->json('responseMessage') ?? 'Unable to initialize Monnify payment.';
            Log::error('Monnify Init Error', ['status' => $response->status(), 'body' => $response->body()]);
            throw new RuntimeException("Monnify payment error: {$errorMsg}");
        }

        $resData = $response->json('responseBody');

        return [
            'status' => true,
            'transaction_reference' => $resData['transactionReference'] ?? null,
            'payment_reference' => $resData['paymentReference'] ?? $paymentReference,
            'checkout_url' => $resData['checkoutUrl'] ?? null,
            'enabled_payment_methods' => $resData['enabledPaymentMethod'] ?? [],
            'raw_response' => $resData,
        ];
    }

    /**
     * Query transaction status on Monnify.
     */
    public function verifyTransaction(string $paymentReference): array
    {
        $token = $this->getAccessToken();

        $response = Http::withToken($token)
            ->get("{$this->baseUrl}/api/v1/merchant/transactions/query", [
                'paymentReference' => $paymentReference,
            ]);

        if (! $response->successful() || ! $response->json('requestSuccessful')) {
            $errorMsg = $response->json('responseMessage') ?? 'Could not verify transaction on Monnify.';
            Log::warning('Monnify Verify Query Error', ['reference' => $paymentReference, 'body' => $response->body()]);
            throw new RuntimeException("Monnify verification error: {$errorMsg}");
        }

        $data = $response->json('responseBody');
        $paymentStatus = strtoupper((string) ($data['paymentStatus'] ?? 'PENDING'));

        return [
            'is_paid' => $paymentStatus === 'PAID',
            'status' => strtolower($paymentStatus),
            'payment_reference' => $data['paymentReference'] ?? $paymentReference,
            'transaction_reference' => $data['transactionReference'] ?? null,
            'amount_paid' => (float) ($data['amountPaid'] ?? 0),
            'total_payable' => (float) ($data['totalPayable'] ?? 0),
            'settlement_amount' => (float) ($data['settlementAmount'] ?? 0),
            'payment_method' => $data['paymentMethod'] ?? 'ACCOUNT_TRANSFER',
            'paid_on' => $data['paidOn'] ?? null,
            'raw_response' => $data,
        ];
    }

    /**
     * Verify Monnify webhook signature using SHA512 hash.
     */
    public function validateWebhookSignature(string $rawPayload, ?string $receivedSignature): bool
    {
        if (empty($receivedSignature)) {
            return false;
        }

        $computedHash = hash_hmac('sha512', $rawPayload, $this->secretKey);

        return hash_equals($computedHash, $receivedSignature);
    }
}
