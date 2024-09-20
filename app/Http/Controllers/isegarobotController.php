<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Telegram\Bot\Api;
use Illuminate\Support\Facades\Http;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Telegram\Bot\Exceptions\TelegramResponseException;
use App\Jobs\ProcessIpsJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Artisan;


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
            $jobs = [];
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
                //$totalRows = count($rows);

                // Split the rows into chunks of 10
                $chunks = array_chunk($rows, 10);
                $totalChunks = count($chunks);

                foreach ($chunks as $chunkIndex => $chunk) {
                    $chunkIndex++; // Adjust for human-readable 1-based indexing

                    // Optional: Validate chunk rows before dispatching
                    $chunk = array_values($chunk); // Ensure indices start from 0

                    // Dispatch job for the current batch
                    $jobs[] =new ProcessIpsJob($chunk, $chatId, $progressMessageId, $chunkIndex, $totalChunks);

                }

            if (!empty($jobs)) {
                bus::chain($jobs)->dispatch();
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
            if($message='Run'){
                $this->sendReply($chatId, $messageId, "start running.");
                Artisan::queue('dns:update');
                $this->sendReply($chatId, $messageId, "running in background.");
            }else{
                $this->sendReply($chatId, $messageId, "No file received.");
            }            
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
