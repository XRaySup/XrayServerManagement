<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class CloudflareApiService
{
    protected $apiUrl = 'https://api.cloudflare.com/client/v4/';
    protected $zoneId;
    protected $apiKey;
    protected $email;

    public function __construct($account)
    {
        $this->setAccount($account);
    }

    public function setAccount($account)
    {
        $config = config("services.cloudflare.accounts.$account");

        if (!$config) {
            throw new \Exception("Cloudflare account configuration not found: $account");
        }

        $this->zoneId = $config['zone_id'];
        $this->apiKey = $config['api_key'];
        $this->email = $config['email'];
    }

    public function isConfiguredCorrectly()
    {
        return isset($this->zoneId) && isset($this->apiKey) && isset($this->email);
    }

    private function makeRequest($method, $endpoint, $data = [])
    {
        try {
            $response = Http::withHeaders([
                        'Authorization' => "Bearer {$this->apiKey}", // Ensure API key is used
                        'Content-Type' => 'application/json',
                    ])->{$method}($this->apiUrl . $endpoint, $data);

            // Log the raw response for debugging
            //\Log::info('API Response', ['response' => $response->json()]);

            if ($response->failed()) {
                \Log::error('API Request Failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                // Decode the response body
                $responseBody = $response->json();

                // Return detailed error information
                return [
                    'success' => false,
                    'errors' => $responseBody['errors'] ?? [['message' => 'Unknown error']],
                    'messages' => $responseBody['messages'] ?? [],
                    'status' => $response->status(),
                ];
            }

            // Decode the response body to JSON array
            return $response->json();
        } catch (\Exception $e) {
            \Log::error('Exception during API request', [
                'message' => $e->getMessage(),
            ]);

            // Return error details
            return [
                'success' => false,
                'errors' => [['message' => 'Exception occurred', 'details' => $e->getMessage()]],
            ];
        }
    }

    public function listDnsRecords()
    {
        $records = [];
        $page = 1;
        $perPage = 100;

        do {
            // Make the request with pagination
            $response = $this->makeRequest('get', "zones/{$this->zoneId}/dns_records", [
                'page' => $page,
                'per_page' => $perPage
            ]);

            if (!$response['success']) {
                return null;
            }

            // Merge the result from the current page into the records array
            $records = array_merge($records, $response['result']);

            // Check the result_info to determine if there are more pages
            $totalPages = $response['result_info']['total_pages'];
            $page++;

        } while ($page <= $totalPages);

        return $records;
    }
    public function getExistingDNSRecord($ip)
    {
        $dnsRecords = $this->listDnsRecords();

        if (!$dnsRecords) {
            return null;
        }

        foreach ($dnsRecords as $record) {
            if ($record['content'] === $ip) {
                return $record;
            }
        }

        return null;
    }

    public function addDnsRecord($name, $content, $ttl = 3600, $proxied = false)
    {
        // Determine the DNS record type based on the content (IPv4 or IPv6)
        if (filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $type = 'A';
        } elseif (filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $type = 'AAAA';
        } else {
            // Default to 'A' if not a valid IP, or handle as needed
            $type = 'A';
        }
        $data = [
            'type' => $type,
            'name' => $name,
            'content' => $content,
            'ttl' => $ttl,
            'proxied' => $proxied,
        ];

        $response = $this->makeRequest('post', "zones/{$this->zoneId}/dns_records", $data);

        if (empty($response['success']) || !empty($response['errors'])) {
            \Log::error('Error adding DNS record', [
                'response' => $response,
            ]);
            return null;
        }

        return $response['result'];
    }

    public function updateDnsRecord($recordId, $name, $content, $ttl = 3600, $proxied = false)
    {
        // Determine the DNS record type based on the content (IPv4 or IPv6)
        // Remove brackets if present and trim whitespace
        if (is_string($content)) {
            $content = trim($content, "[] \t\n\r\0\x0B");
        } elseif (is_array($content)) {
            $content = trim(reset($content), "[] \t\n\r\0\x0B");
        }
        if (filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $type = 'A';
        } elseif (filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $type = 'AAAA';
        } else {
            // Default to 'A' if not a valid IP, or handle as needed
            $type = 'A';
        }
        $data = [
            'type' => $type,
            'name' => $name,
            'content' => $content,
            'ttl' => $ttl,
            'proxied' => $proxied,
        ];

        // Attempt to update the DNS record
        $response = $this->makeRequest('put', "zones/{$this->zoneId}/dns_records/{$recordId}", $data);
        // \Log::info('Cloudflare updateDnsRecord response', [
        //     'response' => $response,
        // ]);
        // Check if 'success' key is present
        if (!isset($response['success']) || !$response['success']) {
            $errorCode = $response['errors'][0]['code'] ?? 'Unknown Code';
            $errorMessage = $response['errors'][0]['message'] ?? 'Unknown Error';

            if ($errorCode === 81058) {
                // Record already exists, so delete it and retry the update
                $response = $this->makeRequest('delete', "zones/{$this->zoneId}/dns_records/{$recordId}");

                if (isset($response['errors'])) {
                    \Log::error('Error deleting DNS record', [
                        'response' => $response,
                    ]);
                    return null;
                }

                // Retry the update after deletion
                // $response = $this->makeRequest('put', "zones/{$this->zoneId}/dns_records/{$recordId}", $data);

                // if (!isset($response['success']) || !$response['success']) {
                //     \Log::error('Error updating DNS record after deletion', [
                //         'response' => $response,
                //     ]);
                //     return null;
                // }
            } else {
                \Log::error('Error updating DNS record', [
                    'response' => $response,
                ]);
                return null;
            }
        }

        return $response['result'];
    }

    public function deleteDnsRecord($recordId)
    {
        $response = $this->makeRequest('delete', "zones/{$this->zoneId}/dns_records/{$recordId}");

        if (empty($response['success']) || !empty($response['errors'])) {
            \Log::error('Error adding DNS record', [
                'response' => $response,
            ]);
            return null;
        }

        return $response['result'];
    }
}