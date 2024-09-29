<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class TestIPResponses extends Command
{
    protected $signature = 'test:ip-responses';
    protected $description = 'Test IP responses for Cloudflare 400 Bad Request';

    public function __construct()
    {
        parent::__construct();
    }

    // The function to check IP responses
    private function check_ip_responses(array $ipAddresses)
    {
        $multiCurl = curl_multi_init();  // Initialize multi-cURL
        $curlHandles = [];  // Store individual cURL handles
        $responses = [];

        foreach ($ipAddresses as $ipAddress) {
            $ch = curl_init();

            curl_setopt($ch, CURLOPT_URL, "http://$ipAddress");
            curl_setopt($ch, CURLOPT_PORT, 443); // Use port 443 without HTTPS
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true); // Include headers in the output
            curl_setopt($ch, CURLOPT_NOBODY, true); // Only fetch the headers
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1); // Connection timeout
            curl_setopt($ch, CURLOPT_TIMEOUT, 1); // Total timeout
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Disable SSL verification

            curl_multi_add_handle($multiCurl, $ch);

            // Keep track of the handle and IP for later reference
            $curlHandles[$ipAddress] = $ch;
        }

        $running = null;
        do {
            curl_multi_exec($multiCurl, $running);
            curl_multi_select($multiCurl);
        } while ($running > 0);

        foreach ($curlHandles as $ipAddress => $ch) {
            $response = curl_multi_getcontent($ch);

            if (curl_errno($ch)) {
                $error = curl_error($ch);
                $this->info("Error for IP $ipAddress: $error");
                $responses[$ipAddress] = false;
            } else {
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $headers = substr($response, 0, $headerSize);

                if ($httpCode == 400 && stripos($headers, 'cloudflare') !== false) {
                    $this->info("Cloudflare server detected with 400 Bad Request for IP $ipAddress");
                    $responses[$ipAddress] = true;
                } else {
                    $this->info("Not a Cloudflare server or not 400 Bad Request for IP $ipAddress");
                    $responses[$ipAddress] = false;
                }
            }

            curl_multi_remove_handle($multiCurl, $ch);
            curl_close($ch);
        }

        curl_multi_close($multiCurl);

        return $responses;
    }

    public function handle()
    {
        // List of IP addresses to test
        $ipList = ['8.8.8.8', '1.1.1.1', '192.168.1.1'];

        $this->info("Testing IP responses...");
        $responses = $this->check_ip_responses($ipList);

        // Output the results
        $this->info("Results:");
        foreach ($responses as $ip => $result) {
            $status = $result ? 'Cloudflare 400 Detected' : 'Not Cloudflare or Not 400';
            $this->info("$ip: $status");
        }
    }
}
