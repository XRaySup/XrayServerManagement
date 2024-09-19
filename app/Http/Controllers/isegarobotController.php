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
        $message = $request->input('message');
        $chatId = $message['chat']['id'];
        $messageId = $message['message_id'];
    
        if (isset($message['document'])) {
            $fileId = $message['document']['file_id'];
            Log::info("Document received.");
            
            // Send initial message about processing start
            $initialReply = "Your file is being processed. Progress: 0%";
            $progressMessageId = $this->sendReply($chatId, $messageId, $initialReply);
    
            if (!$progressMessageId) {
                return response()->json(['status' => 'error', 'message' => 'Failed to send initial reply'], 500);
            }
    
            try {
                // Download the file from Telegram
                $file = $this->telegram->getFile(['file_id' => $fileId]);
                $filePath = $file->getFilePath();
                $fileUrl = "https://api.telegram.org/file/bot" . env('TELEGRAM_BOT_TOKEN') . "/$filePath";
                $fileContents = Http::get($fileUrl)->body();
    
                // Process file contents as CSV
                $rows = array_map('str_getcsv', explode("\n", $fileContents));
                $totalRows = count($rows);
                $totalChunks = ceil($totalRows / 10);
    
                // Manually process rows in batches of 10
                $currentIndex = 0;
                $jobs = [];
                
                while ($currentIndex < $totalRows) {
                    $chunk = array_slice($rows, $currentIndex, 10);
                    $chunk = array_values($chunk); // Remove keys from the chunk
                    $chunkIndex = ceil(($currentIndex + 10) / 10);
    
                    // Create jobs and chain them
                    $jobs[] = new ProcessIpsJob($chunk, $chatId, $progressMessageId, $chunkIndex, $totalChunks);
                    $currentIndex += 10;
                }
    
                // Dispatch the jobs in chain
                if (!empty($jobs)) {
                    $firstJob = array_shift($jobs);
                    $firstJob->chain($jobs)->dispatch();
                }
    
            } catch (\Telegram\Bot\Exceptions\TelegramResponseException $e) {
                Log::error('Telegram API error: ' . $e->getMessage());
                $this->sendReply($chatId, $messageId, "Error: {$e->getMessage()}");
            } catch (\Exception $e) {
                Log::error('General error: ' . $e->getMessage());
                $this->sendReply($chatId, $messageId, "Error: {$e->getMessage()}");
            }
    
        } else {
            // Notify the user that no file was received
            $this->sendReply($chatId, $messageId, "No file received.");
        }
    
        return response()->json(['status' => 'ok']);
    }
    
    
    private function sendReply($chatId, $messageId, $text)
    {
        try {
            $response = $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'reply_to_message_id' => $messageId,
                'text' => $text,
            ]);
            return $response->getMessageId();
        } catch (\Telegram\Bot\Exceptions\TelegramResponseException $e) {
            Log::error('Telegram API error: ' . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            Log::error('General error: ' . $e->getMessage());
            return false;
        }
    }
    


 
}
