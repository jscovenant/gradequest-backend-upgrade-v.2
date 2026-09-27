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
        $this->email = (string) (config('services.whogohost.email') ?: env('WHOGOHOST_EMAIL', 'notifications@gradequest.com.ng'));
        $this->baseUrl = rtrim((string) (config('services.whogohost.base_url') ?: env('WHOGOHOST_BASE_URL', 'https://panel.whogohost.com/modules/addons/DomainsReseller/api/index.php')), '/');
        
        $ns = config('services.whogohost.nameservers');
        $this->defaultNameservers = is_array($ns) && !empty($ns) ? $ns : ['ns1.schoolprofit.ng', 'ns2.schoolprofit.ng'];
        
        $this->autoRegister = (bool) (config('services.whogohost.auto_register') ?? env('WHOGOHOST_AUTO_REGISTER', true));
        $this->defaultContact = (array) (config('services.whogohost.registrant') ?: [
            'firstname' => 'Ezekiel',
            'lastname' => 'Alonge',
            'company' => 'Samaritan Technologies',
            'email' => 'notifications@gradequest.com.ng',
            'address1' => '12 Allen Avenue, Ikeja',
            'city' => 'Ikeja',
            'state' => 'Lagos',
            'postcode' => '100001',
            'country' => 'NG',
            'phonenumber' => '+234.8030000000',
        ]);
    }

    /**
     * Build authentication headers required by WHMCS DomainsReseller Addon API.
     */
    protected function buildHeaders(): array
    {
        $time = gmdate('y-m-d H');
        $token = base64_encode(hash_hmac('sha256', $this->apiKey, $this->email . ':' . $time));

        return [
            'username' => $this->email,
            'token' => $token,
            'apikey' => $this->apiKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Check domain availability for a given name across multiple TLDs.
     *
     * @param string $searchTerm Base domain name (without extension, e.g. "mygreatschool")
     * @param array $tlds Array of TLDs, e.g. ['.com.ng', '.sch.ng', '.ng', '.com', '.org']
     * @return array<string, bool> Map of domain => availability (true/false)
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

        try {
            $endpoint = $this->baseUrl . '/domains/lookup';
            $payload = [
                'searchTerm' => $cleanTerm,
                'tldsToInclude' => $normalizedTlds,
                'isIdnDomain' => false,
                'premiumEnabled' => false,
            ];

            $response = Http::withHeaders($this->buildHeaders())
                ->timeout(10)
                ->post($endpoint, $payload);

            if ($response->successful()) {
                $data = $response->json();
                
                // WHMCS DomainsReseller returns domains in different payload formats
                $domainList = $data['domains'] ?? $data['result'] ?? $data;

                if (is_array($domainList)) {
                    foreach ($domainList as $item) {
                        if (is_array($item) && isset($item['domain'])) {
                            $domainKey = strtolower($item['domain']);
                            $isAvail = in_array(strtolower((string) ($item['status'] ?? '')), ['available', '1', 'true'], true);
                            $results[$domainKey] = $isAvail;
                        }
                    }
                }
            } else {
                Log::warning('Whogohost domain lookup non-200 response', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (Throwable $e) {
            Log::notice('Whogohost lookup fallback triggered: ' . $e->getMessage());
        }

        // Fill any missing TLDs via fast DNS verification fallback
        foreach ($normalizedTlds as $tld) {
            $fullDomain = $cleanTerm . $tld;
            if (! isset($results[$fullDomain])) {
                $results[$fullDomain] = $this->checkAvailabilityFallback($fullDomain);
            }
        }

        return $results;
    }

    /**
     * Register a domain via Whogohost Domain Reseller API.
     */
    public function registerDomain(
        string $domain,
        int $years = 1,
        ?array $customContact = null,
        ?array $customNameservers = null
    ): array {
        $domain = strtolower(trim($domain));
        $nameservers = $customNameservers ?: $this->defaultNameservers;

        // Build contact information combining default registrant with school details
        $contact = array_merge($this->defaultContact, array_filter((array) $customContact));

        // Format nameservers as ns1, ns2, etc.
        $nsPayload = [];
        $i = 1;
        foreach ($nameservers as $ns) {
            $nsPayload['ns' . $i] = trim($ns);
            $i++;
        }

        $payload = [
            'domain' => $domain,
            'regperiod' => max(1, $years),
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
            'addons' => [
                'dnsmanagement' => 1,
                'emailforwarding' => 0,
                'idprotection' => 1,
            ],
        ];

        try {
            $endpoint = $this->baseUrl . '/order/domains/register';
            Log::info('Whogohost Domain Registration Request', ['domain' => $domain, 'payload' => $payload]);

            $response = Http::withHeaders($this->buildHeaders())
                ->timeout(30)
                ->post($endpoint, $payload);

            $data = $response->json();
            Log::info('Whogohost Domain Registration Response', [
                'domain' => $domain,
                'status' => $response->status(),
                'body' => $data ?: $response->body(),
            ]);

            $resultStatus = strtolower((string) ($data['result'] ?? ($data['status'] ?? '')));
            $isSuccess = $response->successful() && in_array($resultStatus, ['success', 'true', 'ok', 'active'], true);

            if ($isSuccess) {
                return [
                    'success' => true,
                    'order_id' => $data['orderid'] ?? ($data['order_id'] ?? ($data['domainid'] ?? null)),
                    'domain_id' => $data['domainid'] ?? ($data['domain_id'] ?? null),
                    'message' => $data['message'] ?? 'Domain registered successfully with Whogohost.',
                    'raw' => $data,
                ];
            }

            $errorMessage = $data['message'] ?? ($data['error'] ?? 'Domain registration failed on registrar end.');

            return [
                'success' => false,
                'order_id' => null,
                'domain_id' => null,
                'message' => $errorMessage,
                'raw' => $data ?: ['body' => $response->body()],
            ];
        } catch (Throwable $e) {
            Log::error('Whogohost Domain Registration Exception: ' . $e->getMessage(), [
                'domain' => $domain,
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'order_id' => null,
                'domain_id' => null,
                'message' => 'Whogohost API connection error: ' . $e->getMessage(),
                'raw' => ['exception' => $e->getMessage()],
            ];
        }
    }

    /**
     * Renew an existing registered domain.
     */
    public function renewDomain(string $domain, int $years = 1): array
    {
        $domain = strtolower(trim($domain));

        try {
            $endpoint = $this->baseUrl . '/order/domains/renew';
            $payload = [
                'domain' => $domain,
                'regperiod' => max(1, $years),
            ];

            $response = Http::withHeaders($this->buildHeaders())
                ->timeout(25)
                ->post($endpoint, $payload);

            $data = $response->json();
            $resultStatus = strtolower((string) ($data['result'] ?? ''));
            $isSuccess = $response->successful() && ($resultStatus === 'success' || ($data['status'] ?? '') === 'success');

            return [
                'success' => $isSuccess,
                'message' => $data['message'] ?? ($isSuccess ? 'Domain renewed successfully.' : 'Renewal failed.'),
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
     * Fetch active nameservers for a domain.
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
     */
    public function updateNameservers(string $domain, array $nameservers): array
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
            $response = Http::withHeaders($this->buildHeaders())
                ->timeout(20)
                ->post($endpoint, $nsPayload);

            $data = $response->json();
            return [
                'success' => $response->successful() && (($data['result'] ?? '') === 'success'),
                'message' => $data['message'] ?? 'Nameservers update submitted.',
                'raw' => $data,
            ];
        } catch (Throwable $e) {
            Log::error('Whogohost updateNameservers failed: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check Reseller Account Balance / Credits.
     */
    public function getAccountBalance(): array
    {
        try {
            $endpoint = $this->baseUrl . '/account/balance';
            $response = Http::withHeaders($this->buildHeaders())
                ->timeout(10)
                ->get($endpoint);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                ];
            }

            return [
                'success' => false,
                'message' => $response->json('message') ?: 'Unable to fetch reseller balance',
                'status' => $response->status(),
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Fast DNS fallback availability checker.
     */
    protected function checkAvailabilityFallback(string $domain): bool
    {
        $records = @dns_get_record($domain, DNS_A | DNS_NS | DNS_SOA);
        return empty($records);
    }
}
