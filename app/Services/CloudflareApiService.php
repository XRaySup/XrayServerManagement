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
        $response = $this->makeRequest('get', "zones/{$this->zoneId}/dns_records");

        if (!$response['success']) {
            return null;
        }

        return $response['result'];
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

    public function addDnsRecord($name, $content, $type = 'A', $ttl = 3600, $proxied = false)
    {
        $data = [
            'type' => $type,
            'name' => $name,
            'content' => $content,
            'ttl' => $ttl,
            'proxied' => $proxied,
        ];

        $response = $this->makeRequest('post', "zones/{$this->zoneId}/dns_records", $data);

        if (isset($response['errors'])) {
            \Log::error('Error adding DNS record', [
                'response' => $response,
            ]);
            return null;
        }

        return $response['result'];
    }

    public function updateDnsRecord($recordId, $name, $content, $type = 'A', $ttl = 3600, $proxied = false)
    {
        $data = [
            'type' => $type,
            'name' => $name,
            'content' => $content,
            'ttl' => $ttl,
            'proxied' => $proxied,
        ];
    
        // Attempt to update the DNS record
        $response = $this->makeRequest('put', "zones/{$this->zoneId}/dns_records/{$recordId}", $data);

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

        if (isset($response['errors'])) {
            \Log::error('Error deleting DNS record', [
                'response' => $response,
            ]);
            return null;
        }

        return $response['result'];
    }
}