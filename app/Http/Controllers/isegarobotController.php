<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Telegram\Bot\Api;
use Illuminate\Support\Facades\Http;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Telegram\Bot\Exceptions\TelegramResponseException;
use App\Jobs\ProcessIpsJob;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class isegarobotController extends Controller
{
    private $telegram;

    public function __construct()
    {
        $this->telegram = new Api(env('TELEGRAM_BOT_TOKEN'));
    }

    public function handleWebhook(Request $request)
    {
        $bot = $this->telegram;
        $message = $request->input('message');
        $chatId = $message['chat']['id'];
        $messageId = $message['message_id'];
        $formattedDateTime = Carbon::now()->format('Y-m-d H:i:s');
    
        // Send initial message about processing start
        $initialReply = "Your file is being processed. Progress: 0%";
        try {
            $response = $bot->sendMessage([
                'chat_id' => $chatId,
                'reply_to_message_id' => $messageId,
                'text' => $initialReply,
            ]);
    
            // Get the message ID of the sent message to edit later
            $progressMessageId = $response->getMessageId();
    
        } catch (\Telegram\Bot\Exceptions\TelegramResponseException $e) {
            Log::error('Telegram API error1: ' . $e->getMessage());
        }
    
        if (isset($message['document'])) {
            $fileId = $message['document']['file_id'];
            Log::info("Document received: {$formattedDateTime}");
    
            try {
                // Download the file from Telegram
                $file = $this->telegram->getFile(['file_id' => $fileId]);
                $filePath = $file->getFilePath();
                $fileUrl = "https://api.telegram.org/file/bot" . env('TELEGRAM_BOT_TOKEN') . "/$filePath";
                $fileContents = Http::get($fileUrl)->body();
    
                // Process file contents as CSV
                $rows = array_map('str_getcsv', explode("\n", $fileContents));
    
                // Batch the rows into groups of 10 and dispatch each batch
                $batchSize = 10;
                $chunks = array_chunk($rows, $batchSize);
                $totalChunks = count($chunks);
    
                foreach ($chunks as $index => $chunk) {
                    // Dispatch each batch of 10 rows as a job and pass the message ID and index
                    ProcessIpsJob::dispatch($chunk, $chatId, $progressMessageId, $index + 1, $totalChunks);
                }
    
            } catch (\Telegram\Bot\Exceptions\TelegramResponseException $e) {
                Log::error('Telegram API error2: ' . $e->getMessage());
                $bot->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "Error: {$e->getMessage()}",
                ]);
            } catch (\Exception $e) {
                Log::error('General error: ' . $e->getMessage());
                $bot->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "Error: {$e->getMessage()}",
                ]);
            }
    
        } else {
            // Notify the user that no file was received
            $replyText = "No file received at {$formattedDateTime}";
            try {
                $bot->sendMessage([
                    'chat_id' => $chatId,
                    'reply_to_message_id' => $messageId,
                ]);
            } catch (\Telegram\Bot\Exceptions\TelegramResponseException $e) {
                Log::error('Telegram API error3: ' . $e->getMessage());
            } catch (\Exception $e) {
                Log::error('General error: ' . $e->getMessage());
            }
        }
    
        return response()->json(['status' => 'ok']);
    }
    

    private function sendMessage($chatId, $text)
    {

        try {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => $text,
            ]);
        } catch (TelegramResponseException $e) {
            // Extract the retry-after value from the exception message
            $retryAfter = $e->getMessage(); // This may contain the retry time in seconds

            // You might need to parse the retry time from the message
            preg_match('/retry after (\d+)/i', $retryAfter, $matches);
            $delay = $matches[1] ?? 60; // Default to 60 seconds if parsing fails
            //print_r($retryAfter );
            \Log::warning("Rate limit exceeded. Retrying after $delay seconds.");

            sleep($delay); // Wait before retrying

            // Retry sending the message
            try {
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => $text,
                ]);
            } catch (TelegramResponseException $e) {
                \Log::error("Failed to send message after retry: " . $e->getMessage());
            }
        }
    }

    public static function replyIps($result, $chatId)
    {
        $controller = new self(); // create an instance to call non-static methods

        $message = '';
        foreach ($result as $ipInfo) {
            $message .= "IP: {$ipInfo['ip']}\n";
            $message .= "Expected Response: " . ($ipInfo['ExpectedResponse'] ? 'True' : 'False') . "\n";
            $message .= "Exists in Log: " . ($ipInfo['ExisInLog'] ? 'True' : 'False') . "\n";
            $message .= "Exists in DNS: " . ($ipInfo['ExistInDNS'] ? 'True' : 'False') . "\n";
            $message .= "--------------------------\n";
        }
        $controller->sendMessage($chatId, $message);
    }
    public static function reply($result, $chatId)
    {
        $controller = new self(); // create an instance to call non-static methods
        $controller->sendMessage($chatId, $result);
    }
}
