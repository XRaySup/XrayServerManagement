<?php

namespace App\Services;
use Telegram\Bot\Laravel\Facades\Telegram;
//use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use App\Services\CloudflareApiService;

//use Telegram\Bot\Api;

//use function Laravel\Prompts\error;

class DnsUpdateService
{
    protected $zoneId;
    protected $apiToken;
    protected $logFile;
    protected $subdomainPattern;
    protected $cloudflare;
    protected $ipLogData;
    protected $ipLog;

    public function __construct()
    {
        //parent::__construct();

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
        $failLimit = 10;
        $validIps = 0;
        $telegram = Telegram::bot('mybot');
        $adminIds = explode(',', env('TELEGRAM_ADMIN_IDS'));
        $progressMessages = [];
        $progressMessageText = 'Running';
        foreach ($adminIds as $adminId) {

            $progressMessages[] = $telegram->sendMessage([
                'chat_id' => trim($adminId),  // Use trim() to remove any extra spaces
                'text' => $progressMessageText,
            ]);
        }
        //print_r($progressMessageIds);
        //return;
        // Ensure the log file exists
        $this->ensureLogExists($this->logFile);
        if ($this->cloudflare->isConfiguredCorrectly() === false) {
            $this->logAndError('Cloudflare API Service is not correctly configured.');
            return;
        }

        //$ips = $this->readCSVFromGoogleDrive('ip.csv');
        $file = Storage::disk('google')->get('DNSUpdate/ip.csv');

        Storage::disk('google')->put('DNSUpdate/ip.csv', '', ['visibility' => 'public']);
        $fileResponse = $this->processFileContent($file);

        if ($fileResponse !== null) {

            $progressMessageText .= "\nProcessing ip.csv :\n" . $fileResponse['message'];
        } else {
            $progressMessageText .= "\nip.csv was empty";
        }
        foreach ($progressMessages as $progressMessage) {

            $this->updateTelegramMessageWithRetry($progressMessage, $progressMessageText);
        }
        //return;


        //isegarobotController::replyIps($ipResults, '');
        // Fetch DNS records from Cloudflare API
        $dnsRecords = $this->cloudflare->listDnsRecords();
        $totalDNS = 0;
        if ($dnsRecords === false) {
            $this->logAndError("Failed to get DNS records. Exiting.");
            exit(1);
        }
        // Extract IPs from DNS records in one line
        $ipsToCheck = array_column($dnsRecords, 'content');

        // Now check the responses for all collected IPs
        $ipResults = $this->check_ip_responses($ipsToCheck); // Check all IPs at once
        //print_r($ipResults);
        //return;

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
            $totalDNS++;
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
            $ExpectedResponse = $ipResults[$ip] ?? false; // Get the response from the array

            $this->logAndInfo("Expected Response: " . ($ExpectedResponse ? 'True' : 'False'));

            // Check and update unexpected response count
            if ($ExpectedResponse === false) {
                // Increment the unexpected response count
                $this->ipLogData[$ip]['response_count']++;

                // If the count reaches 5, delete the record
                if ($this->ipLogData[$ip]['response_count'] >= $failLimit) {
                    $this->logAndError("more than $failLimit times failed respond.");
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


        $progressMessageText .= "\n$validIps valid IPs are available. Total records are $totalDNS.";

        foreach ($progressMessages as $progressMessage) {

            $this->updateTelegramMessageWithRetry($progressMessage, $progressMessageText);
        }
    }
    private function logAndInfo($message)
    {
        // Log message to terminal and log file
        Log::info($message);
        $this->logToFile($this->logFile, $message);
    }

    private function logAndError($message)
    {
        Log::error($message); // Display the error in the terminal
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
    public function processFileContent($filecontent)
    {

        $CountDNSExist = 0;
        $CountExpectedResponse = 0;
        $countIps = 0;
        $countAdded = 0;

        // Load DNS records once
        $dnsRecords = $this->cloudflare->listDnsRecords();
        if (!$dnsRecords) {
            // Return error with status key and message
            return [
                'status' => 'error',
                'message' => 'Could not connect to Cloudflare'
            ];
        }

        // Read the file content into an array of IPs
        $rows = array_map('trim', explode("\n", $filecontent)); // Use trim to remove any whitespace
        //$this->logAndInfo(implode(',', $rows));
        $ipsToCheck = array_filter($rows); // Remove any empty lines
        //$this->logAndInfo(implode(',', $ipsToCheck));

        // Check the IP responses
        $ipResults = $this->check_ip_responses($ipsToCheck);

        foreach ($ipsToCheck as $ip) {
            // Validate the IP address
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $countIps++;
                $ExpectedResponse = $ipResults[$ip] ?? false; // Get the response from the array

                if ($ExpectedResponse) {
                    $CountExpectedResponse++;
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
                    $CountDNSExist++;
                    continue;
                }

                // Add DNS record if the expected response is true and IP doesn't exist in DNS
                if ($ExpectedResponse) {
                    $this->cloudflare->addDNSRecord($this->subdomainPattern, $ip);
                    $countAdded++;
                }
            }
        }

        // If no valid IPs were processed, return null (or another message)
        if ($countIps === 0) {
            return null;  // No message or IPs processed
        }

        // Create a success message summarizing the counts if there are valid IPs
        $summaryMessage = "Process complete! \n" .
            "Total valid IPs checked: $countIps \n" .
            "IPs with expected response: $CountExpectedResponse \n" .
            "IPs already in DNS: $CountDNSExist \n" .
            "New DNS records added: $countAdded";

        // Return success with status, summary message, and count details
        return [
            'status' => 'success',
            'message' => $summaryMessage,
            'data' => [
                'CountDNSExist' => $CountDNSExist,
                'CountExpectedResponse' => $CountExpectedResponse,
                'countIps' => $countIps,
                'countAdded' => $countAdded
            ]
        ];
    }
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
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3); // Connection timeout
            curl_setopt($ch, CURLOPT_TIMEOUT, 3); // Total timeout
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
                $this->logAndInfo("Error for IP $ipAddress: $error");
                $responses[$ipAddress] = false;
            } else {
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $headers = substr($response, 0, $headerSize);

                if ($httpCode == 400 && stripos($headers, 'cloudflare') !== false) {
                    $this->logAndInfo("Cloudflare server detected with 400 Bad Request for IP $ipAddress");
                    $responses[$ipAddress] = true;
                } else {
                    $this->logAndInfo("Not a Cloudflare server or not 400 Bad Request for IP $ipAddress");
                    $responses[$ipAddress] = false;
                }
            }

            curl_multi_remove_handle($multiCurl, $ch);
            curl_close($ch);
        }

        curl_multi_close($multiCurl);

        return $responses;
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
    public function updateTelegramMessageWithRetry($message, $text, $maxRetries = 3)
    {
        $telegram = Telegram::bot('mybot'); // Initialize the Telegram bot

        // Extract chat ID and message ID from the message object
        $chatId = $message->getChat()->getId();
        $messageId = $message->getMessageId();

        $retryCount = 0;
        $success = false;

        while (!$success && $retryCount < $maxRetries) {
            try {
                // Update the message using the Telegram API
                $telegram->editMessageText([
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    'text' => $text,
                ]);
                $success = true; // Exit the loop if successful
            } catch (\Telegram\Bot\Exceptions\TelegramResponseException $e) {
                $retryCount++;
                $this->logAndError("Telegram API error while updating message (attempt $retryCount): " . $e->getMessage());

                // Retry if it's a rate limit error or a recoverable error
                if ($e->getCode() == 429 || $retryCount < $maxRetries) {
                    sleep(1); // Wait before retrying
                } else {
                    break; // Exit loop if it's an unrecoverable error
                }
            }
        }

        if (!$success) {
            $this->logAndError("Failed to update message on Telegram after {$maxRetries} attempts.");
        }

        return $success;
    }
}