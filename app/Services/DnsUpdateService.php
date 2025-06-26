<?php

namespace App\Services;

use Google\Service\CloudBuild\Results;
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
    protected $logFile;
    public $subdomainPattern;
    public $messages;
    protected $cloudflare;
    protected $ipLogData;
    protected $ipLog;
    private $xrayExecutable;
    private $xrayConfigFile;
    private $tempDir;
    private $tempConfigFile;
    private $outputCsv;
    private $validIpsCsv;
    private $fileSize = 102400;
    private $consoleOutput;

    public function __construct($consoleOutput = null)
    {
        $this->determineXrayExecutable();
        $this->xrayConfigFile = base_path('Xray/bin/config.json');
        $this->tempDir = base_path('Xray/temp');
        $this->tempConfigFile = $this->tempDir . '/temp_config.json';
        $this->outputCsv = base_path('Xray/results.csv');
        $this->validIpsCsv = base_path('Xray/ValidIPs.csv');
        $this->subdomainPattern = env('SUBDOMAIN_PATTERN') . env('CLOUDFLARE_DOMAIN');
        $this->logFile = base_path('storage/logs/dns_update.log');

        $this->cloudflare = new CloudflareApiService(env('CLOUDFLARE_DOMAIN'));

        $this->ipLog = base_path('storage/logs/' . $this->subdomainPattern . '.csv');

        $this->ipLogData = $this->loadIpLogData();
        $this->ensureLogExists($this->logFile);
        $this->consoleOutput = $consoleOutput;
    }

    private function determineXrayExecutable()
    {
        $baseDir = base_path('Xray/bin');
        $osFamily = PHP_OS_FAMILY;
        $architecture = php_uname('m');

        if ($osFamily === 'Windows') {
            $this->xrayExecutable = "$baseDir/win-64/xray.exe";
        } elseif ($osFamily === 'Linux') {
            if ($architecture === 'x86_64') {
                $this->xrayExecutable = "$baseDir/linux-64/xray";
            } elseif ($architecture === 'aarch64') {
                $this->xrayExecutable = "$baseDir/linux-arm64/xray";
            } else {
                throw new \Exception("Unsupported architecture: $architecture");
            }
        } else {
            throw new \Exception("Unsupported OS family: $osFamily");
        }

        if (!file_exists($this->xrayExecutable)) {
            throw new \Exception("Xray executable not found: $this->xrayExecutable");
        }

        // Make sure the binary is executable
        //chmod($this->xrayExecutable, 0755);
    }

    public function handle()
    {


        $telegram = Telegram::bot('Proxy');
        $adminIds = explode(',', env('TELEGRAM_PROXY_ADMIN_IDS'));
        $progressMessages = [];
        $progressMessageText = "Checking $this->subdomainPattern :";
        foreach ($adminIds as $adminId) {

            $progressMessages[] = $telegram->sendMessage([
                'chat_id' => trim($adminId),  // Use trim() to remove any extra spaces
                'text' => $progressMessageText,
            ]);
        }
        $this->messages = $progressMessages;
        $this->DNSCheck();
    }
    // public function botDNSCheck($progressMessage)
    // {



    //     $progressMessageText = "Checking $this->subdomainPattern :";
    //     $this->updateTelegramMessageWithRetry($progressMessage, $progressMessageText);

    //     $progressMessageText = $this->subdomainPattern . $this->DNSCheck();

    //     $this->updateTelegramMessageWithRetry($progressMessage, $progressMessageText);


    // }
    public function DNSCheck(): string
    {
        $failLimit = 10;
        $validIps = 0;
        $rayValidIps = 0;
        $totalDNS = 0;
        $summaryMessage = '';
        $this->logAndOutput('Starting DNS check... for ' . $this->subdomainPattern);
        // Ensure the log file exists
        $this->ensureLogExists($this->logFile);
        if ($this->cloudflare->isConfiguredCorrectly() === false) {
            $this->logAndError('Cloudflare API Service is not correctly configured.');
            return 'Cloudflare API Service is not correctly configured.';
        }
        // Fetch DNS records from Cloudflare API

        $dnsRecords = $this->cloudflare->listDnsRecords();

        if ($dnsRecords === false) {
            $this->logAndError("Failed to get DNS records. Exiting.");
            return "Failed to get DNS records. Exiting.";
        }
        // Extract IPs from DNS records in one line
        $ipsToCheck = array_column(array_filter($dnsRecords, function ($record) {
            return strpos($record['name'], $this->subdomainPattern) !== false;
        }), 'content');
        $totalDNS = count($ipsToCheck);

        // Initialize counters and start time
        $validIps = 0;
        $XrayValidIps = 0;
        $startTime = time();
        $chunkSize = 5;
        $ipChunks = array_chunk($ipsToCheck, $chunkSize);
        $lastUpdateTime = time();
        $checkedIps = 0;
        $IPResponses = [];
        foreach ($ipChunks as $chunk) {
            $chunkResponses = $this->checkIpResponses($chunk); // Check IPs in chunks
            $IPResponses = array_merge($IPResponses, $chunkResponses); // Merge chunk responses with previous responses

            $validIps = count(array_filter($IPResponses, function ($response) {
                return $response['400 Response'] === true;
            }));
            $XrayValidIps = count(array_filter($IPResponses, function ($response) {
                return $response['Result'] === true;
            }));

            // Calculate progress and elapsed time
            $checkedIps = count($IPResponses);
            $progress = round(($checkedIps / $totalDNS) * 100, 2);
            $elapsedTime = gmdate("H:i:s", time() - $startTime);
            $summaryMessage = "$this->subdomainPattern :\n" .
                "Process Running! $progress % \n" .
                "Total DNS checked:  $checkedIps / $totalDNS \n" .
                "IPs with expected 400 response: $validIps \n" .
                "IPs with expected Xray response: $XrayValidIps \n" .
                "Time elapsed: $elapsedTime";

            // Update the user every 10 seconds
            $currentTime = time();
            if ($currentTime - $lastUpdateTime >= 10 && $this->messages) {
                foreach ($this->messages as $message) {
                    $this->updateTelegramMessageWithRetry($message, $summaryMessage);
                }
                //$this->updateTelegramMessageWithRetry($this->message, $summaryMessage);
                $lastUpdateTime = $currentTime;
            }
        }
        $elapsedTime = gmdate("H:i:s", time() - $startTime);
        // Create a success message summarizing the counts if there are valid IPs
        $summaryMessage = "$this->subdomainPattern :\n" .
            "Process complete! \n" .
            "Total DNS checked: $totalDNS \n" .
            "IPs with expected 400 response: $validIps \n" .
            "IPs with expected Xray response: $XrayValidIps \n" .
            "Time elapsed: $elapsedTime";
        if ($this->messages) {
            foreach ($this->messages as $message) {
                $this->updateTelegramMessageWithRetry($message, $summaryMessage);
            }
        }
        foreach ($dnsRecords as $record) {

            $type = $record['type'];
            $name = $record['name'];
            $ip = $record['content'];
            $id = $record['id'];

            $proxied = $record['proxied'] ? 'true' : 'false';
            // Skip records that do not match the subdomain pattern
            if (strpos($name, $this->subdomainPattern) === false) {
                $this->logAndInfo("Skipping record: Name='$name' does not match the pattern.");
                continue;
            }
            $ipResponse = $IPResponses[$ip];
            $totalDNS++;
            // Check if proxy is turned off
            if ($proxied === 'true') {

                // Update DNS record to turn on the proxy
                $this->cloudflare->updateDnsRecord($id, $name, $ip);
                //$this->logAndInfo("Proxy has been turned on for record: Name='$name', IP='$ip'.");
            }

            // Initialize response count for the record if not already set
            if (!isset($this->ipLogData[$ip])) {
                $this->logAndInfo("IP='$ip' is not not in the .csv file. We add it to the list");
                $this->ipLogData[$ip] = [

                    'Action' => 'No Change',
                    'Unexpected Response Count' => 0
                ];

            }
            //dump($this->ipLogData[$ip]);
            //dump($Results);
            $this->ipLogData[$ip] = array_merge($this->ipLogData[$ip], [
                'Type' => $type,
                'Name' => $name,
                'Content' => $ip,
                'Proxied' => false,
                '400 Response' => $ipResponse['400 Response'] ?? false,
                'Google Response' => $ipResponse['Google Response'],
                '204 Response' => $ipResponse['204 Response'],
                'Download Time' => $ipResponse['Download Time'],
            ]);
            $ipReport = $this->ipLogData[$ip];
            //dump($ipReport);
            // Check the response from the IP address
            //$this->logAndInfo("Checking IP address: '$ip'");
            //$Expected400Response = $ipResults[$ip] ?? false; // Get the response from the array
            //$ipReport['400 Response'] = $Results400[$ip] ?? false;
            //$this->logAndInfo("Expected Response: " . ($ExpectedResponse ? 'True' : 'False'));

            // Check and update unexpected response count
            if (!$ipReport['400 Response']) {
                // Increment the unexpected response count
                $ipReport['Unexpected Response Count']++;

                // If the count reaches 5, delete the record
                if ($ipReport['Unexpected Response Count'] >= $failLimit) {
                    //$this->logAndError("more than $failLimit times failed respond.");
                    $this->cloudflare->deleteDnsRecord($id);
                    //$this->logAndInfo("Record Removed: Name='$name', IP='$ip'.");
                    $ipReport['Name'] = $name = '-';
                    $ipReport['Action'] = 'Removed';
                } else {
                    // Rename the DNS record if it is not already renamed
                    if (strpos($name, 'deleted.') === false) {
                        $name = 'deleted.' . $name;
                        $this->cloudflare->updateDnsRecord($id, $name, $ip);
                        $ipReport['Name'] = $name;
                        $ipReport['Action'] = 'Renamed';
                    }
                }
            } else {
                // Response is as expected, rename the DNS record back to normal
                $validIps += 1;
                //$ipXrayReport = $this->processIp($ip);
                //$ipReport['Google Response'] = $ipXrayReport['Google Response'];
                //$ipReport['204 Response'] = $ipXrayReport['204 Response'];
                //$ipReport['Download Time'] = $ipXrayReport['Download Time'];
                if ($ipResponse['Result'] == True) {
                    //$this->logAndInfo("IP $ip passed all checks.");
                    $rayValidIps += 1;
                }
                if (strpos($name, 'deleted.') === 0) {
                    $name = str_replace('deleted.', '', $name);
                    $this->cloudflare->updateDnsRecord($id, $name, $ip);
                    $this->ipLogData[$ip]['action'] = 'Restored';
                }
            }
            // Update the CSV data for this record
            $this->ipLogData[$ip] = $ipReport;
        }

        // Write updated CSV data
        $csvHandle = fopen($this->ipLog, 'w');
        if ($csvHandle === false) {
            $this->logAndError("Failed to open CSV file for writing.");
            return "Failed to open CSV file for writing.";
        }
        fputcsv($csvHandle, ['Type', 'Name', 'Content', 'Proxied', 'Action', '400 Response', 'Google Response', '204 Response', 'Download Time', 'Unexpected Response Count']);
        foreach ($this->ipLogData as $data) {
            fputcsv($csvHandle, [
                $data['Type'],
                $data['Name'],
                $data['Content'],
                $data['Proxied'] ? 'True' : 'False',
                $data['Action'],
                $data['400 Response'] ? 'True' : 'False',
                $data['Google Response'],
                $data['204 Response'],
                $data['Download Time'],
                $data['Unexpected Response Count']
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
        // Create a success message summarizing the counts if there are valid IPs
        // $summaryMessage = "$this->subdomainPattern Process complete! \n" .
        //     "Total DNS checked: $totalDNS \n" .
        //     "IPs with expected 400 response: $validIps \n" .
        //     "IPs with expected Xray response: $rayValidIps";
        return $summaryMessage;
    }
    public function checkSingleIP(string $ip): string
{
    // Validate the IP address
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return "Invalid IP address: $ip";
    }

    try {
        // Check the IP response
        $ipResponse = $this->checkIpResponses([$ip]);

        if (isset($ipResponse[$ip])) {
            $response = $ipResponse[$ip];

            // Check if the IP passed the 400 response and Xray checks
            if ($response['400 Response'] && $response['Result']) {
                $this->cloudflare->addDNSRecord($this->subdomainPattern, $ip);
                return "IP $ip is valid, passed all checks, and has been added to DNS records.";
            } elseif ($response['400 Response']) {
                return "IP $ip passed the 400 response check but failed the Xray check.";
            } else {
                return "IP $ip failed the 400 response check.";
            }
        } else {
            return "No response received for IP $ip.";
        }
    } catch (\Exception $e) {
        Log::error("Error checking IP $ip: " . $e->getMessage());
        return "Error checking IP $ip: " . $e->getMessage();
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
    public function processFileContent($filecontent, $message)
    {
        $startTime = time();
        $CountDNSExist = 0;
        $CountExpectedResponse = 0;
        $countIps = 0;
        $countAdded = 0;
        $totaIpsToCheck = 0;
        $CountExpectedResponse400 = 0;

        // Clear the results.csv file and add a header
        $resultsCsvHandle = fopen($this->outputCsv, 'w');
        if ($resultsCsvHandle === false) {
            $this->logAndError("Failed to open results.csv file for writing.");
            return;
        }
        fputcsv($resultsCsvHandle, ['IP Address', 'HTTP Check', 'Xray Check', 'Download Time (ms)', 'File Size']);
        fclose($resultsCsvHandle);

        // Load DNS records once
        $dnsRecords = $this->cloudflare->listDnsRecords();
        if (!$dnsRecords) {
            // Return error with status key and message
            return [
                'status' => 'error',
                'message' => 'Could not connect to Cloudflare'
            ];
        }
        //$this->updateTelegramMessageWithRetry($message, env('CLOUDFLARE_DOMAIN') . ' DNS records loaded successfully.');
        //return;

        // Read the file content into an array of lines
        $rows = array_map('trim', explode("\n", $filecontent)); // Use trim to remove any whitespace

        // Remove the first row if it contains headers
        if (isset($rows[0]) && strpos($rows[0], 'IP地址') !== false) {
            array_shift($rows);
        }

        // Extract IPs from the first column
        $ipsToCheck = [];
        foreach ($rows as $row) {
            $columns = str_getcsv($row);
            if (isset($columns[0])) {
                $ipsToCheck[] = $columns[0];
            }
        }

        // Remove any empty lines
        $ipsToCheck = array_filter($ipsToCheck);
        //dump($ipsToCheck);
        // Log the IPs to check
        //$this->logAndOutput("IPs to check: " . implode(', ', $ipsToCheck));
        $totaIpsToCheck = count($ipsToCheck);
        // Check the IP responses
        //$ipResults = $this->check400Responses($ipsToCheck);
        $chunkSize = 5;
        $ipChunks = array_chunk($ipsToCheck, $chunkSize);
        $lastUpdateTime = time();

        foreach ($ipChunks as $chunk) {
            $ipResults = $this->checkIpResponses($chunk);

            foreach ($chunk as $ip) {
                $countIps++;
                $ipResult = $ipResults[$ip];

                // Validate the IP address
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    //$ExpectedResponse = false;

                    if ($ipResult['400 Response']) {
                        $CountExpectedResponse400 += 1;

                        if ($ipResult['Result']) {
                            $CountExpectedResponse++;

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
                            } else {
                                $this->logAndOutput("IP $ip does not exist in DNS");
                            }
                        }
                    }

                    // Add DNS record if the expected response is true and IP doesn't exist in DNS
                    if ($ipResult['Result'] && !$ExistInDNS) {
                        $this->cloudflare->addDNSRecord($this->subdomainPattern, $ip);
                        $this->logAndOutput("add to dns.");
                        $countAdded++;
                    }
                }

                $progress = round(($countIps / $totaIpsToCheck) * 100, 2);
                $elapsedTime = gmdate("H:i:s", time() - $startTime );
                $summaryMessage = "Process Running! $progress % \n" .
                    "Total valid IPs checked: $countIps of $totaIpsToCheck \n" .
                    "IPs with expected 400 response: $CountExpectedResponse400 \n" .
                    "IPs with expected Xray response: $CountExpectedResponse \n" .
                    "IPs already in DNS $this->subdomainPattern: $CountDNSExist \n" .
                    "New DNS records added: $countAdded \n" .
                    "Time elapsed: $elapsedTime";

                $currentTime = time();
                if ($currentTime - $lastUpdateTime >= 2) {
                    $this->updateTelegramMessageWithRetry($message, $summaryMessage);
                    $lastUpdateTime = $currentTime;
                }
            }
        }

        // If no valid IPs were processed, return null (or another message)
        if ($countIps === 0) {
            return null;  // No message or IPs processed
        }

        // Create a success message summarizing the counts if there are valid IPs
        $elapsedTime = gmdate("H:i:s", time() - $startTime );
        $summaryMessage = "Process complete! \n" .
            "Total valid IPs checked: $countIps of $totaIpsToCheck \n" .
            "IPs with expected 400 response: $CountExpectedResponse400 \n" .
            "IPs with expected Xray response: $CountExpectedResponse \n" .
            "IPs already in DNS $this->subdomainPattern: $CountDNSExist \n" .
            "New DNS records added: $countAdded \n" .
            "Total time elapsed: $elapsedTime";

        $this->updateTelegramMessageWithRetry($message, $summaryMessage);

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
    public function checkIpResponses(array $ipAddresses): array
    {
        $responses = [];

        // Check 400 responses
        $responses400 = $this->check400Responses($ipAddresses);

        foreach ($ipAddresses as $ip) {
            $result = [
                'IP' => $ip,
                '400 Response' => $responses400[$ip] ?? false,
                'Google Response' => '-',
                '204 Response' => '-',
                'Download Time' => '-',
                'File Size' => '-',
                'Result' => false
            ];

            if ($result['400 Response']) {
                $ipXrayReport = $this->processIp($ip);
                $result = array_merge($result, $ipXrayReport);
            }

            $responses[$ip] = $result;
            $this->logAndOutput('IP: ' . $ip . ' 400 Response: ' . ($result['400 Response'] ? 'True' : 'False') . ' Google Response: ' . $result['Google Response'] . ' 204 Response: ' . $result['204 Response'] . ' Download Time: ' . $result['Download Time'] . ' File Size: ' . $result['File Size']);
        }

        return $responses;
    }

    private function check400Responses(array $ipAddresses)
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
                //$this->logAndInfo("Error for IP $ipAddress: $error");
                $responses[$ipAddress] = false;
            } else {
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $headers = substr($response, 0, $headerSize);

                if ($httpCode == 400 && stripos($headers, 'cloudflare') !== false) {
                    //$this->logAndInfo("Cloudflare server detected with 400 Bad Request for IP $ipAddress");
                    $responses[$ipAddress] = true;
                } else {
                    // $this->logAndInfo("Not a Cloudflare server or not 400 Bad Request for IP $ipAddress");
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
            fputcsv($csvHandle, ['Type', 'Name', 'Content', 'Proxied', 'Action', '400 Response', 'Google Response', '204 Response', 'Download Time', 'Unexpected Response Count']);
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
                        'Type' => $row[0],
                        'Name' => $row[1],
                        'Content' => $row[2],
                        'Proxied' => $row[3],
                        'Action' => $row[4],
                        '400 Response' => $row[5],
                        'Google Response' => $row[6],
                        '204 Response' => $row[7],
                        'Download Time' => $row[8],
                        'Unexpected Response Count' => (int) ($row[9] ?? 0),
                    ];
                }
            }
            fclose($csvHandle);
        }
        return $ipLogData;
    }
    public function updateTelegramMessageWithRetry($message, $text, $maxRetries = 3)
    {
        $telegram = Telegram::bot('Proxy'); // Initialize the Telegram bot

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
    public function processIp($ipAddress): array
    {
        //$this->logAndOutput("Checking IP: $ipAddress");
        $result = false;
        $response204 = '-';
        $downloadTime = '-';
        $actualFileSize = '-';
        //$this->logAndOutput("IP $ipAddress passed HTTP 400 check. Starting Xray check...");

        $this->runXrayWithProxyIP($ipAddress);
        sleep(1);

        // // Perform the Google probe check via Xray proxy
        $googleProbeResponse = $this->curlRequest("https://www.google.com", 3, true);

        //     //$this->logAndOutput('Google Probe Response is: ' . $googleProbeResponse);  // Log the response
             $this->logAndOutput("Google Probe Response is: $googleProbeResponse");
   
            // Perform the 204 No Content check via Xray proxy
            $response204 = $this->curlRequest("https://cp.cloudflare.com/generate_204", 3, true);
            $this->logAndOutput('204 Check Response is: ' . $response204);  // Log the response 

            if ($response204 == "204") {
                //$this->logAndOutput("204 Check Response is: $xrayCheck");

                // Download Test
                [$result, $downloadTime, $actualFileSize] = $this->downloadTest();
                if ($result) {
                    $this->logAndOutput("IP $ipAddress passed the 204 check and download test." . $downloadTime . " ms, File Size: $actualFileSize bytes");
                } else {
                    $this->logAndOutput("IP $ipAddress failed the download test.");
                }
                //file_put_contents($this->validIpsCsv, "$ipAddress\n", FILE_APPEND);

                // Record result in CSV
                //$this->recordResult($ipAddress, '400', $response204, $downloadTime, $actualFileSize);


            } else {

                $this->logAndOutput("IP $ipAddress failed the 204 check.");
            }
        

        $this->logAndOutput("closing the xray.");
        // Stop Xray process
        $this->stopXray();

        return [
            'Result' => $result,
            'Google Response' => $googleProbeResponse,
            '204 Response' => $response204,
            'Download Time' => $downloadTime,
            'File Size' => $actualFileSize
        ];
    }

    private function curlRequest($url, $timeout, $useProxy = false)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        if ($useProxy) {
            curl_setopt($ch, CURLOPT_PROXY, "http://127.0.0.1:8080");
        }
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $httpCode;
    }

    private function runXray()
    {
        $command = "$this->xrayExecutable -config $this->tempConfigFile";
        //$this->logAndOutput("Running command: $command");

        if (PHP_OS_FAMILY === 'Windows') {
            // Windows
            $process = popen("start /B " . $command, 'r');
        } else {
            // Unix-like systems
            $process = popen("nohup " . $command . " > /dev/null 2>&1 &", 'r');
        }

        if (is_resource($process)) {
            //$this->logAndOutput("Xray process started successfully.");
            pclose($process);
        } else {
            $this->logAndOutput("Failed to start the xray process.");
        }
    }
    private function runXrayWithProxyIP($proxyIP)
    {
        // Encode IP in Base64 format
        $base64Ip = base64_encode($proxyIP);

        // Update the Xray config with the Base64 IP
        $configContent = file_get_contents($this->xrayConfigFile);
        $updatedConfig = str_replace('PROXYIP', $base64Ip, $configContent);
        file_put_contents($this->tempConfigFile, $updatedConfig);

        // Run Xray in the background and perform 204 check
        $this->runXray();
    }

    private function stopXray()
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows
            exec("taskkill /F /IM xray.exe");
        } else {
            // Linux
            exec("pkill -f xray");
        }
    }

    private function downloadTest(): array
    {
        $downloadedFilePath = "$this->tempDir/temp_downloaded_file";
        $outputFile = "$this->tempDir/temp_output.txt";
        $downloadTime = '-';
        $actualFileSize = '-';
        $result = false;
        // Download the file using cURL
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows
            exec("powershell -command \"& {curl.exe -s -w 'TIME: %{time_total}' --proxy http://127.0.0.1:8080 https://speed.cloudflare.com/__down?bytes=$this->fileSize --output $downloadedFilePath}\" > $outputFile");
        } else {
            // Linux
            exec("curl -s -w 'TIME: %{time_total}' --proxy http://127.0.0.1:8080 https://speed.cloudflare.com/__down?bytes=$this->fileSize --output $downloadedFilePath > $outputFile");
        }
        // Check if the downloaded file size matches the requested size
        if (file_exists($downloadedFilePath)) {
            $actualFileSize = filesize($downloadedFilePath);
            if ($actualFileSize == $this->fileSize) {
                //$this->logAndOutput("Downloaded file size matches the requested size.");
                // Get the download time from the output file
                $output = file_get_contents($outputFile);
                if (preg_match('/TIME: (\d+\.\d+)/', $output, $matches)) {
                    $downloadTime = round($matches[1] * 1000); // Convert to milliseconds
                }
                $result = true;
                unlink($downloadedFilePath);
            } else {
                //$this->logAndOutput("Downloaded file size does not match the requested size.");
            }
        } else {
            $this->logAndOutput("Downloaded file does not exist.");
        }


        return [$result, $downloadTime, $actualFileSize];
    }

    private function recordResult($ipAddress, $httpCheck, $xrayCheck, $downloadTime, $fileSize)
    {
        $result = "$ipAddress,$httpCheck,$xrayCheck,$downloadTime,$fileSize\n";
        file_put_contents($this->outputCsv, $result, FILE_APPEND);
    }

    private function logAndOutput($message)
    {
        Log::info($message);
        if ($this->consoleOutput) {
            call_user_func($this->consoleOutput, $message);
        }
        $this->logToFile($this->logFile, $message);
    }
}
