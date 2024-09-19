<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use App\Services\CloudflareApiService;
use Telegram\Bot\Api;
use Telegram\Bot\Laravel\Facades\Telegram;

class RunDnsUpdate extends Command
{
    public $timeout = 0;
    protected $signature = 'dns:update';
    protected $description = 'Run the DNS update script';

    // Store Cloudflare Zone ID and API Token as protected properties
    protected $zoneId;
    protected $apiToken;
    protected $logFile;
    protected $subdomainPattern;
    protected $cloudflare;
    protected $ipLogData;
    protected $ipLog;

    public function __construct()
    {
        parent::__construct();

        $this->zoneId = env('CLOUDFLARE_ZONE_ID');
        $this->apiToken = env('CLOUDFLARE_API_TOKEN');
        $this->subdomainPattern = env('SUBDOMAIN_PATTERN') . env('CLOUDFLARE_DOMAIN');
        $this->logFile = base_path('storage/logs/dns_update.log');

        $this->cloudflare = new CloudflareApiService(env('CLOUDFLARE_DOMAIN'));

        $this->ipLog = base_path('storage/logs/' . $this->subdomainPattern . '.csv');

        $this->ipLogData = $this->loadIpLogData();
        $this->ensureLogExists($this->logFile);
    }

    public function handle()
    {
        $validIps = 0;
        $telegram = Telegram::bot('mybot');

        $telegram->sendMessage([
            'chat_id' => '5598396909',
            'text' => 'running',
        ]);
        // Ensure the log file exists
        $this->ensureLogExists($this->logFile);
        if ($this->cloudflare->isConfiguredCorrectly() === false) {
            $this->logAndError('Cloudflare API Service is not correctly configured.');
            return;
        }

        $ips = $this->readCSVFromGoogleDrive('ip.csv');

        Storage::disk('google')->put('DNSUpdate/ip.csv', '', ['visibility' => 'public']);
        $ipResults = $this->processIps($ips);

        //isegarobotController::replyIps($ipResults, '');
        // Fetch DNS records from Cloudflare API
        $dnsRecords = $this->cloudflare->listDnsRecords();
        $totalDNS = count($dnsRecords);
        if ($dnsRecords === false) {
            $this->logAndError("Failed to get DNS records. Exiting.");
            exit(1);
        }


        foreach ($dnsRecords as $record) {

            $type = $record['type'];
            $name = $record['name'];
            $ip = $record['content'];
            $id = $record['id'];
            $proxied = $record['proxied'] ? 'true' : 'false';
            $this->logAndInfo("Processing record: Name='$name', IP='$ip', Proxied=$proxied");


            // Skip records that do not match the subdomain pattern
            if (strpos($name, $this->subdomainPattern) === false) {
                $this->logAndInfo("Skipping record: Name='$name' does not match the pattern.");
                continue;
            }

            // Check if proxy is turned off
            if ($proxied === 'true') {
                $this->logAndInfo("Proxy is currently on for record: Name='$name', IP='$ip'. Turning it off...");

                // Update DNS record to turn on the proxy
                $this->cloudflare->updateDnsRecord($id, $name, $ip);
                $this->logAndInfo("Proxy has been turned on for record: Name='$name', IP='$ip'.");
            }

            // Initialize response count for the record if not already set
            if (!isset($this->ipLogData[$ip])) {
                $this->logAndInfo("IP='$ip' is not not in the .csv file. We add it to the list");
                $this->ipLogData[$ip] = [
                    'type' => $type,
                    'name' => $name,
                    'content' => $ip,
                    'proxied' => false,
                    'action' => 'No Change',
                    'response' => '',
                    'response_count' => 0
                ];
            }



            // Check the response from the IP address
            $this->logAndInfo("Checking IP address: '$ip'");
            $ExpectedResponse  = $this->check_ip_response($ip);

            //$response2 = $this->check_ip_response2($ip);

            $this->logAndInfo("Expected Response: " . ($ExpectedResponse ? 'True' : 'False'));

            // Check and update unexpected response count
            if ($ExpectedResponse === false) {
                // Increment the unexpected response count
                $this->ipLogData[$ip]['response_count']++;

                // If the count reaches 5, delete the record
                if ($this->ipLogData[$ip]['response_count'] >= 5) {
                    $this->logAndError("more than 5 times failed respond.");
                    $this->cloudflare->deleteDnsRecord($id);
                    $this->logAndInfo("Record Removed: Name='$name', IP='$ip'.");
                    $name = '-';
                    $this->ipLogData[$ip]['action'] = 'Removed';
                } else {
                    // Rename the DNS record if it is not already renamed
                    if (strpos($name, 'deleted.') === false) {
                        $name = 'deleted.' . $name;
                        $this->cloudflare->updateDnsRecord($id, $name, $ip);
                        $this->ipLogData[$ip]['action'] = 'Renamed';
                    }
                }
            } else {
                // Response is as expected, rename the DNS record back to normal
                $validIps += 1;
                if (strpos($name, 'deleted.') === 0) {
                    $name = str_replace('deleted.', '', $name);
                    $this->cloudflare->updateDnsRecord($id, $name, $ip);
                    $this->ipLogData[$ip]['action'] = 'Restored';
                }
            }

            // Update the CSV data for this record
            $this->ipLogData[$ip]['type'] = $type;
            $this->ipLogData[$ip]['name'] = $name;
            $this->ipLogData[$ip]['content'] = $ip;
            $this->ipLogData[$ip]['proxied'] = $proxied;
            $this->ipLogData[$ip]['response'] = $ExpectedResponse;
        }

        // Write updated CSV data
        $csvHandle = fopen($this->ipLog, 'w');
        if ($csvHandle === false) {
            $this->logAndError("Failed to open CSV file for writing.");
            return;
        }
        fputcsv($csvHandle, ['Type', 'Name', 'Content', 'Proxied', 'Action', 'Response', 'Unexpected Response Count']);
        foreach ($this->ipLogData as $data) {
            fputcsv($csvHandle, [
                $data['type'],
                $data['name'],
                $data['content'],
                $data['proxied'],
                $data['action'],
                $data['response'],
                $data['response_count']
            ]);
        }

        fclose($csvHandle);

        // Upload CSV to Google Drive
        $csvContent = File::get($this->ipLog);
        Storage::disk('google')->put('DNSUpdate/' . $this->subdomainPattern, $csvContent, ['visibility' => 'public']);

        $this->logAndInfo("Results have been written to $this->ipLog and uploaded to Google Drive.");

        // Upload log to Google Drive
        $this->uploadLogToGoogleDrive($this->logFile, 'DNSUpdate/dns_update.log');
        $this->logAndInfo("Log file has been uploaded to Google Drive.");
        $telegram->sendMessage([
            'chat_id' => '5598396909',
            'text' => "$validIps valid IPs are available. Total records are $totalDNS.",
        ]);
    }
    private function LoadIpLogData()
    {

        if (!File::exists($this->ipLog)) {
            $csvHandle = fopen($this->ipLog, 'w');
            if ($csvHandle === false) {
                $this->logAndError("Failed to create CSV file.");
                exit(1);
            }

            // Write CSV header
            fputcsv($csvHandle, ['Type', 'Name', 'Content', 'Proxied', 'Action', 'Response', 'Unexpected Response Count']);
            fclose($csvHandle);
        }
        $ipLogData = [];
        if (($csvHandle = fopen($this->ipLog, 'r')) !== false) {
            $header = fgetcsv($csvHandle); // Read the header line
            while (($row = fgetcsv($csvHandle)) !== false) {
                if (count($row) >= 7) {
                    // Use content (IP address) as the unique key
                    $key = $row[2]; // IP address
                    $ipLogData[$key] = [
                        'type' => $row[0],
                        'name' => $row[1],
                        'content' => $row[2],
                        'proxied' => $row[3],
                        'action' => $row[4],
                        'response' => $row[5],
                        'response_count' => (int)($row[6] ?? 0),
                    ];
                }
            }
            fclose($csvHandle);
        }
        return $ipLogData;
    }

    private function check_ip_response($ipAddress)
    {
        $ch = curl_init();

        // Set the IP address with port 443
        curl_setopt($ch, CURLOPT_URL, "http://$ipAddress");
        curl_setopt($ch, CURLOPT_PORT, 443); // Ensure it's HTTPS port 443
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true); // Include headers in the output
        curl_setopt($ch, CURLOPT_NOBODY, true); // Only fetch the headers
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1); // Connection timeout
        curl_setopt($ch, CURLOPT_TIMEOUT, 1); // Total timeout
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Disable SSL verification for IPs

        // Execute the cURL request
        $response = curl_exec($ch);
        // Handle cURL errors
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            echo $error;
            echo "IP did not respond or error occurred.\n";
            curl_close($ch);
            return false;
        }
        // Handle cURL errors
        // Get the HTTP status code
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // Get the headers from the response
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($response, 0, $headerSize);

        // Check for Cloudflare and HTTP 400 Bad Request
        if ($httpCode == 400 && stripos($headers, 'cloudflare') !== false) {
            $response = 'Cloudflare server detected with 400 Bad Request';
            echo $response . "\n";
            curl_close($ch);
            return true;
        } else {
            $response = 'Not a Cloudflare server or not 400 Bad Request';
            echo $response . "\n";
            curl_close($ch);
            return false;
        }
    }


    private function readCSVFromGoogleDrive($filename)
    {
        $file = Storage::disk('google')->get('DNSUpdate/' . $filename);
        $rows = array_map('str_getcsv', explode("\n", $file));

        // Remove header
        // array_shift($rows);

        return $rows;
    }

    private function logAndInfo($message)
    {
        // Log message to terminal and log file
        $this->info($message);
        $this->logToFile($this->logFile, $message);
    }

    private function logAndError($message)
    {
        $this->error($message); // Display the error in the terminal
        $this->logToFile($this->logFile, "[ERROR] " . $message); // Log it to the file
    }

    private function ensureLogExists($logFile)
    {
        if (!File::exists($logFile)) {
            File::put($logFile, "DNS Update Log\n");
        }
    }

    private function logToFile($logFile, $message)
    {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message\n";
        File::append($logFile, $logMessage);
    }

    private function uploadLogToGoogleDrive($localFilePath, $remoteFilePath)
    {
        $fileContent = File::get($localFilePath);
        Storage::disk('google')->put($remoteFilePath, $fileContent, ['visibility' => 'public']);
    }
    public function processIps($rawIps)
    {
        // Load DNS records once
        $dnsRecords = $this->cloudflare->listDnsRecords();
        if (!$dnsRecords) {
            return; // Exit if DNS records can't be loaded
        }

        foreach ($rawIps as $row) {
            $ip = $row[0];

            // Validate the IP address
            if (filter_var($ip, FILTER_VALIDATE_IP)) {


                if (isset($this->ipLogData[$ip])) {
                    continue;
                }

                // Check if the IP exists in the DNS records
                $ExistInDNS = false;
                foreach ($dnsRecords as $record) {
                    if ($record['content'] === $ip) {
                        $ExistInDNS = true;
                        break;
                    }
                }
                if ($ExistInDNS) {
                    continue;
                }

                $ExpectedResponse = $this->check_ip_response($ip);
                if ($ExpectedResponse) {
                    $this->cloudflare->addDNSRecord($this->subdomainPattern, $ip);
                }
            }
        }
        return;
    }
}
