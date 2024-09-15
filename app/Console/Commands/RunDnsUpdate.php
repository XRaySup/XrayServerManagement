<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
//use Yaza\LaravelGoogleDriveStorage\Gdrive;
use Illuminate\Support\Facades\File;

class RunDnsUpdate extends Command
{
    protected $signature = 'dns:update';
    protected $description = 'Run the DNS update script';

    // Store Cloudflare Zone ID and API Token as protected properties
    protected $zoneId;
    protected $apiToken;
    protected $logFile;
    protected $subdomainPattern;

    public function __construct()
    {
        parent::__construct();

        // Fetch sensitive data from environment variables and store in class properties
        $this->zoneId = env('CLOUDFLARE_ZONE_ID');
        $this->apiToken = env('CLOUDFLARE_API_TOKEN');
        $this->subdomainPattern = env('SUBDOMAIN_PATTERN');
        $this->logFile = base_path('storage/logs/dns_update.log');

        // Ensure the log file exists
        $this->ensureLogExists($this->logFile);

        // Check if the token or zone ID are missing
        if (empty($this->zoneId) || empty($this->apiToken)) {
            // Log and show the error
            $this->logAndError('Cloudflare Zone ID or API Token is not set.');
            exit(1); // Exit the command if required parameters are not available
        }
    }

    public function handle()
    {

        // Path to the CSV file and subdomain pattern
        $csvFile = base_path('storage/logs/' . $this->subdomainPattern . '.csv');

        // Ensure the log file exists
        $this->ensureLogExists($this->logFile);

        if (empty($this->zoneId) || empty($this->apiToken)) {
            $this->logAndError('Cloudflare Zone ID or API Token is not set.');
            return;
        }
        $this->ensure_csv_exists($csvFile);
        $csvData = $this->read_csv($csvFile);
        $rows = $this->readCSVFromGoogleDrive('ip.csv');
        Storage::disk('google')->put('DNSUpdate/ip.csv', '', ['visibility' => 'public']);
        $this->logAndInfo("Start on ip.csv.");
        foreach ($rows as $row) {
            $ip = $row[0];
            if (!isset($csvData[$ip]) && $ip != '') {
                $this->logAndInfo("IP: '$ip' is new!");
                // Check the response from the IP address
                //$this->logAndInfo("Checking IP address: '$ip'");
                $response = $this->check_ip_response($ip);
                $this->logAndInfo("'$ip' Expected Response: '$response'");
                // Set the expected response
                //$expectedResponse = 'HTTP/1.1 400';

                // Check and update unexpected response count
                if ($response === true) {
                    $this->addDNSRecord($ip);
                }
            } else {
                $this->logAndError("IP: '$ip' exists in CSV!");
            }
        }



        // Fetch DNS records from Cloudflare API
        $dnsRecords = $this->get_all_dns_records();

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
                $this->update_dns_record($id, $name, $ip);
                $this->logAndInfo("Proxy has been turned on for record: Name='$name', IP='$ip'.");
            }

            // Initialize response count for the record if not already set
            if (!isset($csvData[$ip])) {
                $this->logAndInfo("IP='$ip' is not not in the .csv file. We add it to the list");
                $csvData[$ip] = [
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
            $response = $this->check_ip_response($ip);
            //$response2 = $this->check_ip_response2($ip);

            $this->logAndInfo("Expected Response: '$response'");
            //$this->logAndInfo("Response: '$response2'");
            // Set the expected response
            //$expectedResponse = 'HTTP/1.1 400';

            // Check and update unexpected response count
            if ($response === false) {
                // Increment the unexpected response count
                //$this->logAndInfo("Expected: False");
                $csvData[$ip]['response_count']++;

                // If the count reaches 5, delete the record
                if ($csvData[$ip]['response_count'] >= 5) {
                    $this->logAndError("more than 5 times failed respond.");
                    $this->delete_dns_record($id);
                    $this->logAndInfo("Record Removed: Name='$name', IP='$ip'.");
                    $name = '-';
                    $csvData[$ip]['action'] = 'Removed';
                } else {
                    // Rename the DNS record if it is not already renamed
                    if (strpos($name, 'deleted.') === false) {
                        $name = 'deleted.' . $name;
                        $this->update_dns_record($id, $name, $ip);
                        $csvData[$ip]['action'] = 'Renamed';
                    }
                }
            } else {
                //$this->logAndInfo("Expected: True");
                // Response is as expected, rename the DNS record back to normal
                if (strpos($name, 'deleted.') === 0) {
                    $name = str_replace('deleted.', '', $name);
                    $this->update_dns_record($id, $name, $ip);
                    $csvData[$ip]['action'] = 'Restored';
                }
            }

            // Update the CSV data for this record
            $csvData[$ip]['type'] = $type;
            $csvData[$ip]['name'] = $name;
            $csvData[$ip]['content'] = $ip;
            $csvData[$ip]['proxied'] = $proxied;
            $csvData[$ip]['response'] = $response;
        }

        // Write updated CSV data
        $csvHandle = fopen($csvFile, 'w');
        if ($csvHandle === false) {
            $this->logAndError("Failed to open CSV file for writing.");
            return;
        }
        fputcsv($csvHandle, ['Type', 'Name', 'Content', 'Proxied', 'Action', 'Response', 'Unexpected Response Count']);
        foreach ($csvData as $data) {
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
        $csvContent = File::get($csvFile);
        Storage::disk('google')->put('DNSUpdate/' . $this->subdomainPattern, $csvContent, ['visibility' => 'public']);

        $this->logAndInfo("Results have been written to $csvFile and uploaded to Google Drive.");

        // Upload log to Google Drive
        $this->uploadLogToGoogleDrive($this->logFile, 'DNSUpdate/dns_update.log');
        $this->logAndInfo("Log file has been uploaded to Google Drive.");
    }

    private function get_all_dns_records()
    {
        $url = "https://api.cloudflare.com/client/v4/zones/$this->zoneId/dns_records";

        $curl = curl_init($url);

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $this->apiToken",
            "Content-Type: application/json"
        ]);

        $response = curl_exec($curl);

        if (curl_errno($curl)) {
            $this->logAndError('Error: ' . curl_error($curl));
            curl_close($curl);
            return null;
        }

        curl_close($curl);

        // Decode JSON response into an array
        $responseArray = json_decode($response, true);

        // Check if the request was successful
        if (!$responseArray['success']) {
            $this->logAndError('Error fetching DNS records: ' . $responseArray['errors'][0]['message']);
            return null;
        }

        // Return the list of DNS records
        return $responseArray['result'];
    }
    private function getExistingDNSRecord($ip)
    {
        // Get all DNS records for the zone
        $dnsRecords = $this->get_all_dns_records();

        if (!$dnsRecords) {
            return null; // If we couldn't retrieve the DNS records, return null
        }

        // Loop through the records to find a match
        foreach ($dnsRecords as $record) {
            if ($record['content'] === $ip) {
                // A record with the same name and content already exists
                return $record;
            }
        }

        return null;
    }
    private function update_dns_record($recordId, $name, $ip)
    {
        $url = "https://api.cloudflare.com/client/v4/zones/$this->zoneId/dns_records/$recordId";
        $proxied = 'false';
        $data = [
            'type'    => 'A',
            'name'    => $name,
            'content' => $ip,
            'proxied' => ($proxied === 'true') ? true : false,
        ];

        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $this->apiToken",
            "Content-Type: application/json"
        ]);

        $response = curl_exec($curl);
        curl_close($curl);
        // Decode the JSON response
        $responseArray = json_decode($response, true);

        // Check if the request was successful
        if (!$responseArray['success']) {
            // Extract the error code and message
            $errorCode = $responseArray['errors'][0]['code'] ?? 'Unknown Code';
            $errorMessage = $responseArray['errors'][0]['message'] ?? 'Unknown Error';
            $this->logAndError($errorMessage . ' code: ' . $errorCode);
            if ($errorCode === 81058) {
                $this->delete_dns_record($recordId);
                $this->update_dns_record($recordId, $name,  $ip);
            }
            return [
                'success' => false,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
            ];
        }
        $this->logAndInfo($response);
        return ['success' => true, 'result' => $responseArray['result']];
    }
    // private function check_ip_response($ipAddress)
    // {
    //     $ch = curl_init();
    //     curl_setopt($ch, CURLOPT_URL, "$ipAddress:443");
    //     curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    //     curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    //     curl_setopt($ch, CURLOPT_TIMEOUT, 3);

    //     $response = curl_exec($ch);

    //     if (curl_errno($ch)) {
    //         $response = curl_error($ch);
    //     } else {
    //         $response = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    //     }

    //     curl_close($ch);

    //     return $response ? "HTTP/1.1 $response" : "No Response";
    // }
    private function check_ip_response($ipAddress)
    {
        $ch = curl_init();

        // Set the IP address with port 443
        curl_setopt($ch, CURLOPT_URL, "http://$ipAddress");
        curl_setopt($ch, CURLOPT_PORT, 443); // Ensure it's HTTPS port 443
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true); // Include headers in the output
        curl_setopt($ch, CURLOPT_NOBODY, true); // Only fetch the headers
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3); // Connection timeout
        curl_setopt($ch, CURLOPT_TIMEOUT, 3); // Total timeout
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Disable SSL verification for IPs
        
        // Execute the cURL request
        $response = curl_exec($ch);

        // Handle cURL errors
    // Get the HTTP status code
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    // Get the headers from the response
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    // Check if 'Cloudflare' is in the headers
    $isCloudflare = stripos($headers, 'cloudflare') !== false;
    // Check if the body contains 'Shopify'
    $containsShopify = stripos($body, 'shopify') !== false;
    // Close the cURL session
    curl_close($ch);
    // Check for Cloudflare and HTTP 400 Bad Request
    if ($httpCode == 400 && $isCloudflare !== false) {
        $response = 'Cloudflare server detected with 400 Bad Request';
        echo $response . "\n";

        curl_close($ch);
        return true;
    } else {
        if($containsShopify){
            $this->logAndError('shopify');
            }
        $response = 'Not a Cloudflare server or not 400 Bad Request';
        echo $response . "\n";
        curl_close($ch);
        return false;
    }
        

    }
    
    private function delete_dns_record($recordId)
    {
        $url = "https://api.cloudflare.com/client/v4/zones/$this->zoneId/dns_records/$recordId";

        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "DELETE");
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $this->apiToken",
            "Content-Type: application/json"
        ]);

        $response = curl_exec($curl);

        if (curl_errno($curl)) {
            $this->logAndError('Error: ' . curl_error($curl));
            curl_close($curl);
            return false;
        }

        curl_close($curl);

        // Return the API response as JSON for handling success or failure
        return json_decode($response, true);
    }
    private function ensure_csv_exists($csvFile)
    {
        if (!File::exists($csvFile)) {
            $csvHandle = fopen($csvFile, 'w');
            if ($csvHandle === false) {
                $this->logAndError("Failed to create CSV file.");
                exit(1);
            }

            // Write CSV header
            fputcsv($csvHandle, ['Type', 'Name', 'Content', 'Proxied', 'Action', 'Response', 'Unexpected Response Count']);
            fclose($csvHandle);
        }
    }

    private function read_csv($csvFile)
    {
        $csvData = [];
        if (($csvHandle = fopen($csvFile, 'r')) !== false) {
            $header = fgetcsv($csvHandle); // Read the header line
            while (($row = fgetcsv($csvHandle)) !== false) {
                if (count($row) >= 7) {
                    // Use content (IP address) as the unique key
                    $key = $row[2]; // IP address
                    $csvData[$key] = [
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
        return $csvData;
    }
    private function readCSVFromGoogleDrive($filename)
    {
        $file = Storage::disk('google')->get('DNSUpdate/' . $filename);
        $rows = array_map('str_getcsv', explode("\n", $file));

        // Remove header
       // array_shift($rows);

        return $rows;
    }
    private function addDNSRecord($ip)
    {

        // First, check for existing DNS records
        $existingRecord = $this->getExistingDNSRecord($ip);

        if ($existingRecord) {
            // Extract the 'name' from the existing record
            $existingRecordName = $existingRecord['name'];
            // Stop adding the record if it already exists
            $this->logAndError("A DNS record with IP $ip already exists for $existingRecordName.");
            return [
                'success' => false,
                'message' => "DNS record already exists.",
            ];
        }

        $url = "https://api.cloudflare.com/client/v4/zones/$this->zoneId/dns_records";

        // Prepare the data to be sent in the request
        $data = [
            'type' => 'A',
            'name' => $this->subdomainPattern,
            'content' => $ip,
            'ttl' => 1,
            'proxied' => False,
        ];

        // Initialize cURL
        $ch = curl_init($url);

        // Set cURL options
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $this->apiToken",
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

        // Execute the cURL request and capture the response
        $response = curl_exec($ch);

        // Check for errors
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return ['error' => $error];
        }

        // Close the cURL session
        curl_close($ch);

        // Decode the JSON response
        $responseArray = json_decode($response, true);

        // Check if the request was successful
        if (!$responseArray['success']) {
            // Extract the error code and message
            $errorCode = $responseArray['errors'][0]['code'] ?? 'Unknown Code';
            $errorMessage = $responseArray['errors'][0]['message'] ?? 'Unknown Error';
            $this->logAndError($errorMessage);
            return [
                'success' => false,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
            ];
        }

        $this->logAndInfo('Record Added Successfully');
        return ['success' => true, 'result' => $responseArray['result']];
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
}
