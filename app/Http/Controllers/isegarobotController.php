<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Telegram\Bot\Api;
use Illuminate\Support\Facades\Http;
use App\Jobs\ProcessIpsJob;
use Illuminate\Support\Facades\Log;
use App\Services\DnsUpdateService;
use App\Jobs\BotDNSCheckJob;



class isegarobotController extends Controller
{
    private $telegram;
    protected $dnsUpdateService;

    public function __construct(DnsUpdateService $dnsUpdateService)
    {
        $this->telegram = new Api(env('TELEGRAM_BOT_TOKEN'));
        $this->dnsUpdateService = $dnsUpdateService;
    }

    public function handleWebhook(Request $request)
    {
        $message = $request->input('message');
        $chatId = $message['chat']['id'];
        $messageId = $message['message_id'];
        $userName = $message['from']['username'] ?? 'Unknown';
        $firstName = $message['from']['first_name'] ?? 'Unknown';
        $lastName = $message['from']['last_name'] ?? 'Unknown';

        $adminIds = explode(',', env('TELEGRAM_ADMIN_IDS'));

        if (!in_array((int) $chatId, $adminIds)) {
            // Notify admins about the unauthorized attempt
            foreach ($adminIds as $adminId) {
                $this->sendReply(trim($adminId), null, "Non-admin user tried to interact: \nID: $chatId\nUsername: $userName\nName: $firstName $lastName");
            }

            // Send message to the non-admin user
            $this->sendReply($chatId, $messageId, "You are not authorized to use this bot.");

            // Return response after non-admin check
            return response()->json(['status' => 'ok']);
        }

        // Continue if the user is an admin
        if (isset($message['document'])) {
            $fileId = $message['document']['file_id'];

            // Send initial message about processing start
            $initialReply = "Your file is being processed.";
            $progressMessage = $this->sendReply($chatId, $messageId, $initialReply);

            if (!$progressMessage) {
                return response()->json(['status' => 'error', 'message' => 'Failed to send initial reply'], 500);
            }

            try {
                // Download the file from Telegram
                $file = $this->telegram->getFile(['file_id' => $fileId]);
                $filePath = $file->getFilePath();
                $fileUrl = "https://api.telegram.org/file/bot" . env('TELEGRAM_BOT_TOKEN') . "/$filePath";
                $fileContents = Http::get($fileUrl)->body();
                //Log::info('File contents: ' . print_r($fileContents, true));

                // Dispatch a single job with the entire file contents

                ProcessIpsJob::dispatch($fileContents, $progressMessage);
                //$progressMessage = $this->sendReply($chatId, $messageId, 'test');

            } catch (\Telegram\Bot\Exceptions\TelegramResponseException $e) {
                Log::error('Telegram API error: ' . $e->getMessage());
                $this->sendReply($chatId, $messageId, "Error: {$e->getMessage()}");
            } catch (\Exception $e) {
                Log::error('General error: ' . $e->getMessage());
                $this->sendReply($chatId, $messageId, "Error: {$e->getMessage()}");
            }
        } else {
            if (isset($message['text'])) {
                if ($message['text'] === '/testdns') {
                    // Send initial message about processing start
                    
                    $initialReply = "Running the command.";
                    
                    $progressMessage = $this->sendReply($chatId, $messageId, $initialReply);
                    // Dispatch the BotDNSCheckJob
                    BotDNSCheckJob::dispatch($progressMessage);
                    //$this->sendReply($chatId, $messageId, "DNS update command has been executed.");
                } else {
                    $this->sendReply($chatId, $messageId, "No file received.");
                }
            } else {
                $this->sendReply($chatId, $messageId, "No text message received.");
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
            return $response;
        } catch (\Telegram\Bot\Exceptions\TelegramResponseException $e) {
            Log::error('Telegram API error: ' . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            Log::error('General error: ' . $e->getMessage());
            return false;
        }
    }

    // public function processIps(Request $request)
    // {
    //     $message = $request->input('message');
    //     $chatId = $message['chat']['id'];
    //     $messageId = $message['message_id'];

    //     // Continue if the user is an admin
    //     if (isset($message['document'])) {
    //         $fileId = $message['document']['file_id'];

    //         // Send initial message about processing start
    //         $initialReply = "Your file is being processed.";
    //         $progressMessage = $this->sendReply($chatId, $messageId, $initialReply);

    //         if (!$progressMessage) {
    //             return response()->json(['status' => 'error', 'message' => 'Failed to send initial reply'], 500);
    //         }

    //         try {
    //             // Download the file from Telegram
    //             $file = $this->telegram->getFile(['file_id' => $fileId]);
    //             $filePath = $file->getFilePath();
    //             $fileUrl = "https://api.telegram.org/file/bot" . env('TELEGRAM_BOT_TOKEN') . "/$filePath";
    //             $fileContents = Http::get($fileUrl)->body();

    //             Log::info('File downloaded from Telegram.');
    //             Log::info('Dispatching ProcessIpsJob.');

    //             // Dispatch a single job with the entire file contents
    //             ProcessIpsJob::dispatch($fileContents, $progressMessage);

    //             Log::info('ProcessIpsJob dispatched.');

    //         } catch (\Telegram\Bot\Exceptions\TelegramResponseException $e) {
    //             Log::error('Telegram API error: ' . $e->getMessage());
    //             $this->sendReply($chatId, $messageId, "Error: {$e->getMessage()}");
    //         } catch (\Exception $e) {
    //             Log::error('General error: ' . $e->getMessage());
    //             $this->sendReply($chatId, $messageId, "Error: {$e->getMessage()}");
    //         }
    //     } else {
    //         if (isset($message['text'])) {
    //             if ($message['text'] === '\testDNS') {
    //                 // Send initial message about processing start
    //                 $initialReply = "Running the command.";
    //                 $progressMessage = $this->sendReply($chatId, $messageId, $initialReply);



    //                 $this->sendReply($chatId, $messageId, "DNS update command has been executed.");
    //             } else {
    //                 $this->sendReply($chatId, $messageId, "No file received.");
    //             }
    //         } else {
    //             $this->sendReply($chatId, $messageId, "No text message received.");
    //         }
    //     }
    // }
}
