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
    
        $replyText = "Your message received at {$formattedDateTime}";
    
        try {
            $bot->sendMessage([
                'chat_id' => $chatId,
                'reply_to_message_id' => $messageId,
                'text' => $replyText,
            ]);
        } catch (\Telegram\Bot\Exceptions\TelegramResponseException $e) {
            // Handle Telegram API exceptions
            Log::error('Telegram API error1: ' . $e->getMessage());
        } catch (\Exception $e) {
            // Handle other exceptions
            Log::error('General error: ' . $e->getMessage());
        }
        sleep(1);
        

        if (isset($message['document'])) {
            $fileId = $message['document']['file_id'];
            $replyText = "Your file received at {$formattedDateTime}";
            Log::info("try sending : Your file received at {$formattedDateTime}");
            try {
                $bot->sendMessage([
                    'chat_id' => $chatId,
                    'reply_to_message_id' => $messageId,
                    'text' => $replyText,
                ]);
            } catch (\Telegram\Bot\Exceptions\TelegramResponseException $e) {
                // Handle Telegram API exceptions
                Log::error('Telegram API error2: ' . $e->getMessage());
            } catch (\Exception $e) {
                // Handle other exceptions
                Log::error('General error: ' . $e->getMessage());
            }

            try {
                $file = $this->telegram->getFile(['file_id' => $fileId]);
                $filePath = $file->getFilePath();

                $fileUrl = "https://api.telegram.org/file/bot" . env('TELEGRAM_BOT_TOKEN') . "/$filePath";

                // Download the file using Http facade
                $fileContents = Http::get($fileUrl)->body();
                $this->sendMessage($chatId, "File Received.");

                // Process file contents as CSV
                $rows = array_map('str_getcsv', explode("\n", $fileContents));
                //return response()->json(['status' => 'ok']);
                // Dispatch job for processing
                ProcessIpsJob::dispatch($rows, $chatId);

                // Optionally, send a confirmation message to the user
                $this->sendMessage($chatId, "File processed successfully.");
            } catch (TelegramSDKException $e) {
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "Error: {$e->getMessage()}"
                ]);
            }
        } else {
            $replyText = "No file received at {$formattedDateTime}";
    
            try {
                $bot->sendMessage([
                    'chat_id' => $chatId,
                    'reply_to_message_id' => $messageId,
                    'text' => $replyText,
                ]);
            } catch (\Telegram\Bot\Exceptions\TelegramResponseException $e) {
                // Handle Telegram API exceptions
                Log::error('Telegram API error3: ' . $e->getMessage());
            } catch (\Exception $e) {
                // Handle other exceptions
                Log::error('General error: ' . $e->getMessage());
            }
            return response()->json(['status' => 'ok']);
            $this->sendMessage($chatId, "No file received.");
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
        if (count($result) > 10) {
            $controller->replyToFile($chatId, $result);
        } else {
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
    }
}
