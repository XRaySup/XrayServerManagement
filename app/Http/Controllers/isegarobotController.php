<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Telegram\Bot\Api;
use Illuminate\Support\Facades\Http;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Illuminate\Support\Facades\Storage;

class isegarobotController extends Controller
{
    private $telegram;

    public function __construct()
    {
        $this->telegram = new Api(env('TELEGRAM_BOT_TOKEN'));
    }

    public function handleWebhook(Request $request)
    {
        $message = $request->input('message');
        $chatId = $message['chat']['id'];
        if (isset($message['document'])) {
            $fileId = $message['document']['file_id'];

            try {
                $file = $this->telegram->getFile(['file_id' => $fileId]);
                $filePath = $file->getFilePath();
                $fileUrl = "https://api.telegram.org/file/bot" . env('TELEGRAM_BOT_TOKEN') . "/$filePath";

                // Download the file
                $fileContents = file_get_contents($fileUrl);
                $this->sendMessage($chatId, "File Received.");

                $fileContents = Http::get($fileUrl)->body();

                // Process file contents
                $this->processFileContents($fileContents, $chatId);

                // Optionally, send a confirmation message to the user

                $this->sendMessage($chatId, "File processed successfully.");
            } catch (TelegramSDKException $e) {
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "Error: {$e->getMessage()}"
                ]);
            }
        } else {

            $this->sendMessage($chatId, "No file received.");
        }

        return response()->json(['status' => 'ok']);
    }
    private function readCSVFromGoogleDrive($filename)
    {
        $file = Storage::disk('google')->get('DNSUpdate/' . $filename);
        $rows = array_map('str_getcsv', explode("\n", $file));

        // Remove header
        array_shift($rows);

        return $rows;
    }
    private function processFileContents($contents, $chatId)
    {
        // Split contents into lines
        $lines = explode("\n", trim($contents));
        $validIps = [];

        foreach ($lines as $line) {
            $ip = trim($line);

            // Validate the IP address
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $ipresponse = $this->check_ip_response($ip);
                
                // Set the expected response
                $expectedResponse = 'HTTP/1.1 400';

                // Check and update unexpected response count
                if (strpos($ipresponse, $expectedResponse) === false) {
                    $this->sendMessage($chatId, "ip '$ip' : Wrong response.");
                } else {
                    $validIps[] = $ip;
                    $this->sendMessage($chatId, "ip '$ip' : Expected response.");
                }
            }
        }
        // Save valid IPs to Google Drive
        if (!empty($validIps)) {
            $this->saveIpsToGoogleDrive($validIps);
        }
    }
        // Function to write the CSV file
        private function writeCSVToGoogleDrive($filename, $data)
        {
            // Convert the data to CSV format
            $handle = fopen('php://temp', 'r+');
            foreach ($data as $row) {
                fputcsv($handle, $row);
            }
            rewind($handle);
            $csvContent = stream_get_contents($handle);
            fclose($handle);
    
            // Write the updated content back to Google Drive
            Storage::disk('google')->put('DNSUpdate/' . $filename, $csvContent);
        }
    private function saveIpsToGoogleDrive(array $ips)
    {
        $rows = $this->readCSVFromGoogleDrive('ip.csv');
        // Prepare new data to append
        foreach ($ips as $ip) {
            // Assuming the CSV has only IPs, you might need to adjust this based on your actual CSV structure
            $rows[] = [$ip];
        }
        // Write updated data to Google Drive
        $this->writeCSVToGoogleDrive('ip.csv', $rows);
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
    private function sendMessage($chatId, $text)
    {
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
        ]);
    }
}
