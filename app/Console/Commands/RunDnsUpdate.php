<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
// use Illuminate\Support\Facades\Storage;
// use Illuminate\Support\Facades\File;
// use App\Services\CloudflareApiService;
// use Telegram\Bot\Api;
// use Telegram\Bot\Laravel\Facades\Telegram;
use App\Services\DnsUpdateService;

class RunDnsUpdate extends Command
{
    
    protected $signature = 'dns:update';
    protected $description = 'Run the DNS update script';

    // Store Cloudflare Zone ID and API Token as protected properties
    // protected $zoneId;
    // protected $apiToken;
    // protected $logFile;
    // protected $subdomainPattern;
    // protected $cloudflare;
    // protected $ipLogData;
    // protected $ipLog;

    public function __construct()
    {
        parent::__construct();

        // $this->zoneId = env('CLOUDFLARE_ZONE_ID');
        // $this->apiToken = env('CLOUDFLARE_API_TOKEN');
        // $this->subdomainPattern = env('SUBDOMAIN_PATTERN') . env('CLOUDFLARE_DOMAIN');
        // $this->logFile = base_path('storage/logs/dns_update.log');

        // $this->cloudflare = new CloudflareApiService(env('CLOUDFLARE_DOMAIN'));

        // $this->ipLog = base_path('storage/logs/' . $this->subdomainPattern . '.csv');

        // $this->ipLogData = $this->loadIpLogData();
        // $this->ensureLogExists($this->logFile);
    }

    public function handle()
    {
        $dnsUpdateService = new DnsUpdateService(function ($message) {
            $this->info($message);
        });
        $dnsUpdateService->handle();

    }




    // private function check_ip_response($ipAddress)
    // {
    //     $ch = curl_init();

    //     // Set the IP address with port 443
    //     curl_setopt($ch, CURLOPT_URL, "http://$ipAddress");
    //     curl_setopt($ch, CURLOPT_PORT, 443); // Ensure it's HTTPS port 443
    //     curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    //     curl_setopt($ch, CURLOPT_HEADER, true); // Include headers in the output
    //     curl_setopt($ch, CURLOPT_NOBODY, true); // Only fetch the headers
    //     curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1); // Connection timeout
    //     curl_setopt($ch, CURLOPT_TIMEOUT, 1); // Total timeout
    //     curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Disable SSL verification for IPs

    //     // Execute the cURL request
    //     $response = curl_exec($ch);
    //     // Handle cURL errors
    //     if (curl_errno($ch)) {
    //         $error = curl_error($ch);
    //         echo $error;
    //         echo "IP did not respond or error occurred.\n";
    //         curl_close($ch);
    //         return false;
    //     }
    //     // Handle cURL errors
    //     // Get the HTTP status code
    //     $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    //     // Get the headers from the response
    //     $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    //     $headers = substr($response, 0, $headerSize);

    //     // Check for Cloudflare and HTTP 400 Bad Request
    //     if ($httpCode == 400 && stripos($headers, 'cloudflare') !== false) {
    //         $response = 'Cloudflare server detected with 400 Bad Request';
    //         echo $response . "\n";
    //         curl_close($ch);
    //         return true;
    //     } else {
    //         $response = 'Not a Cloudflare server or not 400 Bad Request';
    //         echo $response . "\n";
    //         curl_close($ch);
    //         return false;
    //     }
    // }




 



}
