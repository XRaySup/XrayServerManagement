<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Telegram\Bot\Api;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

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

            // Get file path from Telegram API
            $fileData = $this->telegram->getFile(['file_id' => $fileId]);

            if (isset($fileData['result']['file_path'])) {
                $this->sendMessage($chatId, "File Received.");
                $filePath = $fileData['result']['file_path'];
                $fileUrl = "https://api.telegram.org/file/bot" . env('TELEGRAM_BOT_TOKEN') . "/{$filePath}";

                // Fetch the file contents
                $fileContents = Http::get($fileUrl)->body();

                // Process file contents
                $this->processFileContents($fileContents,$chatId);
                
                // Optionally, send a confirmation message to the user
                
                $this->sendMessage($chatId, "File processed successfully.");
            }
        } else {

            $this->sendMessage($chatId, "No file received.");
        }

        return response()->json(['status' => 'ok']);
    }

    private function processFileContents($contents,$chatId)
    {
        // Split contents into lines
        $lines = explode("\n", trim($contents));

        foreach ($lines as $line) {
            $ip = trim($line);

            // Validate the IP address
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $this->sendMessage($chatId, "ip: $ip");
                // Do something with the valid IP address
                // Example: Log the valid IP or perform some action
                // Log::info("Valid IP address: " . $ip);
                // Your custom logic here
            } else {
                // Handle invalid IP addresses if needed
                // Log::warning("Invalid IP address: " . $ip);
            }
        }
    }

    private function sendMessage($chatId, $text)
    {
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
        ]);
    }
}

