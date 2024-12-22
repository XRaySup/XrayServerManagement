<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Models\Server;
use Telegram\Bot\Laravel\Facades\Telegram;

class HandleTelegramMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $requestData;
    private $telegram;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($requestData)
    {
        $this->requestData = $requestData;

    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $message = $this->requestData['message'];
        $botIdentifier = $this->requestData['bot'] ?? 'unknown_bot';
        $chatId = $message['chat']['id'];
        $messageId = $message['message_id'];
        $userName = $message['from']['username'] ?? 'Unknown';
        $firstName = $message['from']['first_name'] ?? 'Unknown';
        $lastName = $message['from']['last_name'] ?? 'Unknown';

        // Log the received message for debugging
        Log::info('Received message from bot ' . $botIdentifier . ': ', $message);
        switch ($botIdentifier)
        {
            case 'Xray_testbot':
                $this->handleXrayBot();
                break;
            case 'isegarobot':
                $this->handleIsegaBot();
                break;
            case 'xraybot':
                $this->handleXrayBot();
                break;
            default:
                Log::error('Unknown bot identifier: ' . $botIdentifier);
        }
        $adminIds = explode(',', env('TELEGRAM_XADMIN_IDS'));
        if (!in_array((int) $chatId, $adminIds)) {
            // Notify admins about the unauthorized attempt
            foreach ($adminIds as $adminId) {
                $this->sendReply(trim($adminId), null, "Non-admin user tried to interact: \nID: $chatId\nUsername: $userName\nName: $firstName $lastName");
            }

            // Send message to the non-admin user
            $this->sendReply($chatId, $messageId, "You are not authorized to use this bot.");

            // Return response after non-admin check
            return;
        }


        if (isset($message['text'])) {
            if ($message['text'] === '/usage') {
                $servers = Server::all();
                // Prepare the table message in Markdown format
                $reply = "*Remark* | *Usage (GB)*\n--- | ---\n";
                $totalUsage = 0;
                foreach ($servers as $server) {
                    if ($server->status == "ONLINE") {
                        $reply .= "{$server->remark} | *{$server->todayUsage}* \n";
                        $totalUsage += $server->todayUsage;
                    }
                }
                $reply .= "Total | *{$totalUsage}* \n";
                try {
                    $response = $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'reply_to_message_id' => $messageId,
                        'text' => $reply,
                        'parse_mode' => 'Markdown'
                    ]);
                    return $response;
                } catch (\Exception $e) {
                    Log::error('Telegram API error: ' . $e->getMessage());
                }
            } else {
                $this->sendReply($chatId, $messageId, "No file received.");
            }
        } else {
            $this->sendReply($chatId, $messageId, "No text message received.");
        }

        $text = $message['text'] ?? '';

        // Process the message
        if ($text === '/start') {
            $this->sendReply($chatId, $messageId, 'Welcome to the bot!');
        } else {
            $this->sendReply($chatId, $messageId, 'You said: ' . $text);
        }
    }

    private function handleIsegaBot()
    {
        $message = $this->requestData['message'];
        $botIdentifier = $this->requestData['bot'] ?? 'unknown_bot';
        $chatId = $message['chat']['id'];
        $messageId = $message['message_id'];
        $userName = $message['from']['username'] ?? 'Unknown';
        $firstName = $message['from']['first_name'] ?? 'Unknown';
        $lastName = $message['from']['last_name'] ?? 'Unknown';
        // Add your logic to handle IsegaBot messages here
        Log::info('Handling IsegaBot message from ' . $userName);
        $this->sendReply($chatId, $messageId, "IsegaBot received your message.");
    }

    private function handleXrayBot()
    {
        $this->telegram = Telegram::bot('FreeXrayBot');
        // Add your logic to handle XrayBot messages here
        $message = $this->requestData['message'];
        $botIdentifier = $this->requestData['bot'] ?? 'unknown_bot';
        $chatId = $message['chat']['id'];
        $messageId = $message['message_id'];
        $userName = $message['from']['username'] ?? 'Unknown';
        $firstName = $message['from']['first_name'] ?? 'Unknown';
        $lastName = $message['from']['last_name'] ?? 'Unknown';

        Log::info('Handling XrayBot message from ' . $userName);
        $this->sendReply($chatId, $messageId, "XrayBot received your message.");
    }

    // private function checkUser($chatId, $messageId, $text)
    // {
    //     $adminIds = explode(',', env('TELEGRAM_XADMIN_IDS'));
    //     if (!in_array((int) $chatId, $adminIds)) {
    //         // Notify admins about the unauthorized attempt
    //         foreach ($adminIds as $adminId) {
    //             $this->sendReply(trim($adminId), null, "Non-admin user tried to interact: \nID: $chatId\nUsername: $userName\nName: $firstName $lastName");
    //         }

    //         // Send message to the non-admin user
    //         $this->sendReply($chatId, $messageId, "You are not authorized to use this bot.");

    //         // Return response after non-admin check
    //         return;
    //     }
    // }

    private function sendReply($chatId, $messageId, $text)
    {
        try {
            $response = $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'reply_to_message_id' => $messageId,
                'text' => $text,
            ]);
            return $response;
        } catch (\Exception $e) {
            Log::error('Telegram API error: ' . $e->getMessage());
        }
    }
}
