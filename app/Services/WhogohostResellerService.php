<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class WhogohostResellerService
{
    private string $apiKey;
    private string $email;
    private string $baseUrl;
    private array $defaultNameservers;
    private bool $autoRegister;
    private array $defaultContact;

    public function __construct()
    {
        $this->apiKey = (string) (config('services.whogohost.api_key') ?: env('WHOGOHOST_API_KEY', 'gN1A3YIoNfWDlEqmP05nnlcNs9Gj2FkY'));
        $this->email = (string) (config('services.whogohost.email') ?: env('WHOGOHOST_EMAIL', 'jscovenant05@gmail.com'));
        $this->baseUrl = rtrim((string) (config('services.whogohost.base_url') ?: env('WHOGOHOST_BASE_URL', 'https://whogohost.com/host/modules/addons/DomainsReseller/api/index.php')), '/');
        
        $ns = config('services.whogohost.nameservers');
        $this->defaultNameservers = is_array($ns) && !empty($ns) ? $ns : ['ns1.schoolprofit.ng', 'ns2.schoolprofit.ng'];
        
        $this->autoRegister = (bool) (config('services.whogohost.auto_register') ?? env('WHOGOHOST_AUTO_REGISTER', true));
        $this->defaultContact = (array) (config('services.whogohost.registrant') ?: [
            'firstname' => 'Ezekiel',
            'lastname' => 'Alonge',
            'companyname' => 'Samaritan Technologies',
            'email' => 'jscovenant05@gmail.com',
            'address1' => '12 Allen Avenue, Ikeja',
            'city' => 'Ikeja',
            'state' => 'Lagos',
            'postcode' => '100001',
            'country' => 'NG',
            'phonenumber' => '+234.8030000000',
        ]);
    }

    /**
     * Build authentication headers required by Whogohost WHMCS DomainsReseller API.
     */
    protected function buildHeaders(): array
    {
        $time = gmdate('y-m-d H');
        $token = base64_encode(hash_hmac('sha256', $this->apiKey, $this->email . ':' . $time));

        return [
            'username' => $this->email,
            'token' => $token,
            'Accept' => 'application/json',
        ];
    }

    /**
     * Get Reseller Account Credits from Whogohost.
     * Endpoint: GET /billing/credits
     */
    public function getCredits(): array
    {
        try {
            $endpoint = $this->baseUrl . '/billing/credits';
            $response = Http::withHeaders($this->buildHeaders())
                ->timeout(12)
                ->get($endpoint);

            if ($response->successful()) {
                $raw = $response->body();
                $clean = trim($raw, "\"'\n\r ");
                $numericBalance = is_numeric($clean) ? (float) $clean : 0.00;

                return [
                    'success' => true,
                    'balance' => $numericBalance,
                    'currency' => 'NGN',
                    'formatted' => '₦' . number_format($numericBalance, 2),
                    'raw' => $response->json() ?? $raw,
                ];
            }

            return [
                'success' => false,
                'balance' => 0.00,
                'message' => 'Unable to fetch reseller credits from Whogohost.',
                'raw' => $response->body(),
            ];
        } catch (Throwable $e) {
            Log::error('Whogohost getCredits failed: ' . $e->getMessage());
            return [
                'success' => false,
                'balance' => 0.00,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get Available TLDs supported by Whogohost Reseller.
     * Endpoint: GET /tlds
     */
    public function getAvailableTlds(): array
    {
        try {
            $endpoint = $this->baseUrl . '/tlds';
            $response = Http::withHeaders($this->buildHeaders())
                ->timeout(10)
                ->get($endpoint);

            if ($response->successful()) {
                $data = $response->json();
                return is_array($data) ? $data : [];
            }
        } catch (Throwable $e) {
            Log::warning('Whogohost getAvailableTlds error: ' . $e->getMessage());
        }

        return ['.com', '.com.ng', '.sch.ng', '.ng', '.org', '.net'];
    }

    /**
     * Check domain availability for a given name across multiple TLDs using official Registry RDAP & DNS.
     */
    public function lookup(string $searchTerm, array $tlds = ['.com.ng', '.sch.ng', '.ng', '.com', '.org']): array
    {
        $cleanTerm = strtolower(trim($searchTerm));
        $cleanTerm = preg_replace('/\.[a-z.]+$/', '', $cleanTerm);
        $results = [];

        // Format TLDs cleanly
        $normalizedTlds = array_map(function ($tld) {
            return str_starts_with($tld, '.') ? $tld : '.' . $tld;
        }, $tlds);

        foreach ($normalizedTlds as $tld) {
            $fullDomain = $cleanTerm . $tld;
            $results[$fullDomain] = $this->isDomainAvailable($fullDomain);
        }

        return $results;
    }

    /**
     * Check authoritative domain availability via Registry RDAP & DNS.
     */
    public function isDomainAvailable(string $domain): bool
    {
        $domain = strtolower(trim($domain));

        // 1. Fast DNS verification (if the domain actively resolves on public DNS, it is registered)
        if (@checkdnsrr($domain, 'A') || @checkdnsrr($domain, 'NS') || @checkdnsrr($domain, 'MX') || @checkdnsrr($domain, 'SOA')) {
            return false;
        }
        $ip = @gethostbyname($domain);
        if (!empty($ip) && $ip !== $domain) {
            return false;
        }

        // 2. Query Official Authoritative RDAP Registry
        try {
            $rdapUrl = null;
            if (str_ends_with($domain, '.ng')) {
                // Official Nigeria Internet Registration Association (NiRA) Registry RDAP
                $rdapUrl = "https://rdap.nic.net.ng/domain/{$domain}";
            } elseif (str_ends_with($domain, '.com') || str_ends_with($domain, '.net')) {
                // Official Verisign Registry RDAP
                $rdapUrl = "https://rdap.verisign.com/com/v1/domain/{$domain}";
            } elseif (str_ends_with($domain, '.org')) {
                // Official Public Interest Registry (PIR) RDAP
                $rdapUrl = "https://rdap.publicinterestregistry.org/rdap/domain/{$domain}";
            } else {
                $rdapUrl = "https://rdap.org/domain/{$domain}";
            }

            if ($rdapUrl) {
                $response = Http::timeout(4)
                    ->withoutVerifying()
                    ->withUserAgent('SchoolProfit-DomainLookup/1.0')
                    ->get($rdapUrl);

                $status = $response->status();
                if ($status === 200) {
                    // Domain object actively registered in the registry
                    return false;
                }

                if ($status === 404) {
                    // Domain not found in the official registry -> Available
                    return true;
                }
            }
        } catch (Throwable $e) {
            Log::notice("RDAP check exception for {$domain}: " . $e->getMessage());
        }

        // 3. Fallback DNS
        return ! (@checkdnsrr($domain, 'A') || @checkdnsrr($domain, 'NS') || @checkdnsrr($domain, 'SOA'));
    }

    /**
     * Register a domain via Whogohost Domain Reseller API.
     * Endpoint: POST /order/domains/register
     */
    public function registerDomain(
        string $domain,
        int $years = 1,
        ?array $customContact = null,
        ?array $customNameservers = null
    ): array {
        $domain = strtolower(trim($domain));
        $nameservers = $customNameservers ?: $this->defaultNameservers;

        // Merge contact info
        $contact = array_merge($this->defaultContact, array_filter((array) $customContact));

        // Format nameservers as ns1, ns2, etc.
        $nsPayload = [];
        $i = 1;
        foreach ($nameservers as $ns) {
            $nsPayload['ns' . $i] = trim($ns);
            $i++;
        }

        $params = [
            'domain' => $domain,
            'regperiod' => max(1, $years),
            'paymentmethod' => config('services.whogohost.payment_method', 'banktransfer'),
            'addons' => [
                'dnsmanagement' => 1,
                'emailforwarding' => 0,
                'idprotection' => 1,
            ],
            'nameservers' => $nsPayload,
            'contacts' => [
                'firstname' => $contact['firstname'] ?? 'School',
                'lastname' => $contact['lastname'] ?? 'Admin',
                'companyname' => $contact['company'] ?? ($contact['companyname'] ?? 'SchoolProfit Education'),
                'email' => $contact['email'] ?? $this->email,
                'address1' => $contact['address1'] ?? '12 Allen Avenue, Ikeja',
                'city' => $contact['city'] ?? 'Ikeja',
                'state' => $contact['state'] ?? 'Lagos',
                'postcode' => $contact['postcode'] ?? '100001',
                'country' => $contact['country'] ?? 'NG',
                'phonenumber' => $contact['phonenumber'] ?? '+234.8030000000',
            ],
        ];

        try {
            $endpoint = $this->baseUrl . '/order/domains/register';
            Log::info('Whogohost RegisterDomain Request', ['domain' => $domain, 'params' => $params]);

            // Whogohost API requires form-urlencoded payload (http_build_query)
            $response = Http::asForm()
                ->withHeaders($this->buildHeaders())
                ->timeout(35)
                ->post($endpoint, $params);

            $data = $response->json();
            Log::info('Whogohost RegisterDomain Response', [
                'domain' => $domain,
                'status' => $response->status(),
                'body' => $data ?: $response->body(),
            ]);

            $resultStatus = strtolower((string) ($data['result'] ?? ($data['status'] ?? '')));
            $hasSuccess = $response->successful() && in_array($resultStatus, ['success', 'true', 'ok', 'active'], true);

            if ($hasSuccess) {
                return [
                    'success' => true,
                    'order_id' => $data['orderid'] ?? ($data['order_id'] ?? ($data['domainid'] ?? null)),
                    'domain_id' => $data['domainid'] ?? ($data['domain_id'] ?? null),
                    'message' => $data['message'] ?? 'Domain registered successfully with Whogohost.',
                    'raw' => $data,
                ];
            }

            $errorMessage = $data['error'] ?? ($data['message'] ?? 'Domain registration failed on registrar end.');

            return [
                'success' => false,
                'order_id' => null,
                'domain_id' => null,
                'message' => $errorMessage,
                'raw' => $data ?: ['body' => $response->body()],
            ];
        } catch (Throwable $e) {
            Log::error('Whogohost RegisterDomain Exception: ' . $e->getMessage(), [
                'domain' => $domain,
            ]);

            return [
                'success' => false,
                'order_id' => null,
                'domain_id' => null,
                'message' => 'Whogohost API error: ' . $e->getMessage(),
                'raw' => ['exception' => $e->getMessage()],
            ];
        }
    }

    /**
     * Renew an existing registered domain.
     * Endpoint: POST /order/domains/renew
     */
    public function renewDomain(string $domain, int $years = 1): array
    {
        $domain = strtolower(trim($domain));

        try {
            $endpoint = $this->baseUrl . '/order/domains/renew';
            $params = [
                'domain' => $domain,
                'regperiod' => max(1, $years),
            ];

            $response = Http::asForm()
                ->withHeaders($this->buildHeaders())
                ->timeout(25)
                ->post($endpoint, $params);

            $data = $response->json();
            $resultStatus = strtolower((string) ($data['result'] ?? ''));
            $isSuccess = $response->successful() && ($resultStatus === 'success' || ($data['status'] ?? '') === 'success');

            return [
                'success' => $isSuccess,
                'message' => $data['message'] ?? ($isSuccess ? 'Domain renewed successfully.' : ($data['error'] ?? 'Renewal failed.')),
                'raw' => $data,
            ];
        } catch (Throwable $e) {
            Log::error('Whogohost Domain Renewal Exception: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'raw' => [],
            ];
        }
    }

    /**
     * Transfer domain into Whogohost.
     * Endpoint: POST /order/domains/transfer
     */
    public function transferDomain(string $domain, string $eppCode, int $years = 1): array
    {
        $domain = strtolower(trim($domain));

        try {
            $endpoint = $this->baseUrl . '/order/domains/transfer';
            $params = [
                'domain' => $domain,
                'eppcode' => $eppCode,
                'regperiod' => max(1, $years),
            ];

            $response = Http::asForm()
                ->withHeaders($this->buildHeaders())
                ->timeout(30)
                ->post($endpoint, $params);

            $data = $response->json();
            $isSuccess = $response->successful() && (($data['result'] ?? '') === 'success');

            return [
                'success' => $isSuccess,
                'message' => $data['message'] ?? ($data['error'] ?? 'Transfer request submitted.'),
                'raw' => $data,
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Fetch active nameservers for a domain.
     * Endpoint: GET /domains/{domain}/nameservers
     */
    public function getNameservers(string $domain): ?array
    {
        $domain = strtolower(trim($domain));

        try {
            $endpoint = $this->baseUrl . '/domains/' . urlencode($domain) . '/nameservers';
            $response = Http::withHeaders($this->buildHeaders())
                ->timeout(15)
                ->get($endpoint);

            if ($response->successful()) {
                return $response->json();
            }
        } catch (Throwable $e) {
            Log::warning('Whogohost getNameservers failed: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Update nameservers for a domain.
     * Endpoint: POST /domains/{domain}/nameservers
     */
    public function saveNameservers(string $domain, array $nameservers): array
    {
        $domain = strtolower(trim($domain));
        $nsPayload = [];
        $i = 1;
        foreach ($nameservers as $ns) {
            $nsPayload['ns' . $i] = trim($ns);
            $i++;
        }

        try {
            $endpoint = $this->baseUrl . '/domains/' . urlencode($domain) . '/nameservers';
            $response = Http::asForm()
                ->withHeaders($this->buildHeaders())
                ->timeout(20)
                ->post($endpoint, $nsPayload);

            $data = $response->json();
            return [
                'success' => $response->successful() && (($data['result'] ?? '') === 'success'),
                'message' => $data['message'] ?? ($data['error'] ?? 'Nameservers updated.'),
                'raw' => $data,
            ];
        } catch (Throwable $e) {
            Log::error('Whogohost saveNameservers failed: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get domain DNS records.
     * Endpoint: GET /domains/{domain}/dns
     */
    public function getDns(string $domain): ?array
    {
        $domain = strtolower(trim($domain));

        try {
            $endpoint = $this->baseUrl . '/domains/' . urlencode($domain) . '/dns';
            $response = Http::withHeaders($this->buildHeaders())
                ->timeout(15)
                ->get($endpoint);

            if ($response->successful()) {
                return $response->json();
            }
        } catch (Throwable $e) {
            Log::warning('Whogohost getDns failed: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Save domain DNS records.
     * Endpoint: POST /domains/{domain}/dns
     */
    public function saveDns(string $domain, array $dnsRecords): array
    {
        $domain = strtolower(trim($domain));

        try {
            $endpoint = $this->baseUrl . '/domains/' . urlencode($domain) . '/dns';
            $response = Http::asForm()
                ->withHeaders($this->buildHeaders())
                ->timeout(20)
                ->post($endpoint, ['records' => $dnsRecords]);

            $data = $response->json();
            return [
                'success' => $response->successful() && (($data['result'] ?? '') === 'success'),
                'message' => $data['message'] ?? ($data['error'] ?? 'DNS updated.'),
                'raw' => $data,
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get domain information (status, expiry date, registration date).
     * Endpoint: GET /domains/{domain}/information
     */
    public function getDomainInformation(string $domain): ?array
    {
        $domain = strtolower(trim($domain));

        try {
            $endpoint = $this->baseUrl . '/domains/' . urlencode($domain) . '/information';
            $response = Http::withHeaders($this->buildHeaders())
                ->timeout(15)
                ->get($endpoint);

            if ($response->successful()) {
                return $response->json();
            }
        } catch (Throwable $e) {
            Log::warning('Whogohost getDomainInformation failed: ' . $e->getMessage());
        }

        return null;
    }
}
