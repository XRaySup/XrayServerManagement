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

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {

        // Fetch environment variables
        $zoneId = env('CLOUDFLARE_ZONE_ID');
        $apiToken = env('CLOUDFLARE_API_TOKEN');
        $subdomainPattern = env('SUBDOMAIN_PATTERN');; // Adjust this if necessary

        // Path to the CSV file and subdomain pattern
        $csvFile = base_path('storage/logs/' . $subdomainPattern . '.csv');

        if (empty($zoneId) || empty($apiToken)) {
            $this->error('Cloudflare Zone ID or API Token is not set.');
            return;
        }

        // Fetch DNS records from Cloudflare API
        $dnsRecords = json_decode($this->get_all_dns_records($zoneId, $apiToken), true);

        if ($dnsRecords === false) {
            $this->error("Failed to get DNS records. Exiting.");
            exit(1);
        }

        $this->ensure_csv_exists($csvFile);
        $csvData = $this->read_csv($csvFile);

        $csvHandle = fopen($csvFile, 'w');
        if ($csvHandle === false) {
            $this->error("Failed to open CSV file for writing.");
            return;
        }

        fputcsv($csvHandle, ['Type', 'Name', 'Content', 'Proxied', 'Action', 'Response', 'Unexpected Response Count']);

        foreach ($dnsRecords['result'] as $record) {
            $type = $record['type'];
            $name = $record['name'];
            $content = $record['content'];
            $id = $record['id'];
            $proxied = $record['proxied'] ? 'true' : 'false';

            // Skip records that do not match the subdomain pattern
            if (strpos($name, $subdomainPattern) === false) {
                $this->info("Skipping record: Name='$name' does not match the pattern.");
                continue;
            }
            $key = $content; // Use IP address as the unique key

            // Initialize response count for the record if not already set
            if (!isset($csvData[$key])) {
                $csvData[$key] = [
                    'type' => $type,
                    'name' => $name,
                    'content' => $content,
                    'proxied' => $proxied,
                    'action' => 'No Change',
                    'response' => '',
                    'response_count' => 0
                ];
            }

            $this->info("Processing record: Name='$name', Content='$content', Proxied=$proxied");

            // Check the response from the IP address
            $this->info("Checking IP address: $content");
            $response = $this->check_ip_response($content);
            $this->info("Response: $response");
            // Set the expected response
            $expectedResponse = 'HTTP/1.1 400';

            // Check and update unexpected response count
            if (strpos($response, $expectedResponse) === false) {
                // Increment the unexpected response count
                $this->info("Expected: False");
                $csvData[$key]['response_count']++;

                // If the count reaches 5, delete the record
                if ($csvData[$key]['response_count'] >= 5) {
                    $this->delete_dns_record($zoneId, $id, $apiToken);
                    $name = '-';
                    $csvData[$key]['action'] = 'Removed';
                } else {
                    // Rename the DNS record if it is not already renamed
                    if (strpos($name, 'deleted.') === false) {
                        $name = 'deleted.' . $name;
                        $this->update_dns_record($zoneId, $id, $apiToken, $name, $proxied, $type, $content);
                        $csvData[$key]['action'] = 'Renamed';
                    }
                }
            } else {
                $this->info("Expected: True");
                // Response is as expected, rename the DNS record back to normal
                if (strpos($name, 'deleted.') === 0) {
                    $name = str_replace('deleted.', '', $name);
                    $this->update_dns_record($zoneId, $id, $apiToken, $name, $proxied, $type, $content);
                    $csvData[$key]['action'] = 'Restored';
                }
            }

            // Update the CSV data for this record
            $csvData[$key]['type'] = $type;
            $csvData[$key]['name'] = $name;
            $csvData[$key]['content'] = $content;
            $csvData[$key]['proxied'] = $proxied;
            $csvData[$key]['response'] = $response;
        }

        // Write updated CSV data
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
        Storage::disk('google')->put('DNSUpdate/' . $subdomainPattern, $csvContent, ['visibility' => 'public']);

        $this->info("Results have been written to $csvFile and uploaded to Google Drive.");
    }

    private function get_all_dns_records($zone_id, $api_token)
    {
        $url = "https://api.cloudflare.com/client/v4/zones/$zone_id/dns_records";

        $curl = curl_init($url);

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $api_token",
            "Content-Type: application/json"
        ]);

        $response = curl_exec($curl);

        if (curl_errno($curl)) {
            $this->error('Error: ' . curl_error($curl));
        }

        curl_close($curl);

        return $response;
    }
    private function update_dns_record($zoneId, $recordId, $apiToken, $name, $proxied, $type, $content)
    {
        $url = "https://api.cloudflare.com/client/v4/zones/$zoneId/dns_records/$recordId";

        $data = [
            'type'    => $type,
            'name'    => $name,
            'content' => $content,
            'proxied' => ($proxied === 'true') ? true : false,
        ];

        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $apiToken",
            "Content-Type: application/json"
        ]);

        $response = curl_exec($curl);
        curl_close($curl);

        return $response;
    }
    private function check_ip_response($ipAddress)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "$ipAddress:443");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $response = curl_error($ch);
        } else {
            $response = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        }

        curl_close($ch);

        return $response ? "HTTP/1.1 $response" : "No Response";
    }
    private function delete_dns_record($zoneId, $recordId, $apiToken)
    {
        $url = "https://api.cloudflare.com/client/v4/zones/$zoneId/dns_records/$recordId";

        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "DELETE");
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $apiToken",
            "Content-Type: application/json"
        ]);

        $response = curl_exec($curl);

        if (curl_errno($curl)) {
            $this->error('Error: ' . curl_error($curl));
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
                $this->error("Failed to create CSV file.");
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
}
