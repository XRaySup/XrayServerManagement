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
    protected $botIdentifier;
    private $telegram;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($requestData)
    {
        $this->requestData = $requestData;
        $this->botIdentifier = request()->query('bot', 'unknown_bot'); // Extract bot name from query parameter

        Log::info('HandleTelegramMessage job created.');
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        Log::info('HandleTelegramMessage job started.');
        switch ($this->botIdentifier) {
            case 'Test':
                $this->telegram = Telegram::bot($this->botIdentifier);
                $this->handleFreeXrayBotMessage();
            case 'free_xray_bot':
                $this->telegram = Telegram::bot('FreeXrayBot');
                $this->handleFreeXrayBotMessage();
                break;
            // case 'another_bot':
            //     $this->telegram = Telegram::bot('AnotherBot');
            //     $this->handleAnotherBotMessage();
            //     break;
            default:
                Log::error('Unknown bot: ' . $this->botIdentifier);
        }
        // $message = $this->requestData['message'];
        // $botIdentifier = $this->botIdentifier; // Use the extracted bot name
        // $chatId = $message['chat']['id'];
        // $messageId = $message['message_id'];
        // $userName = $message['from']['username'] ?? 'Unknown';
        // $firstName = $message['from']['first_name'] ?? 'Unknown';
        // $lastName = $message['from']['last_name'] ?? 'Unknown';

        // // Log the received message for debugging
        // Log::info('Received message from bot ' . $botIdentifier . ': ', $this->requestData);

        // $adminIds = explode(',', env('TELEGRAM_XADMIN_IDS'));
        // if (!in_array((int) $chatId, $adminIds)) {
        //     // Notify admins about the unauthorized attempt
        //     foreach ($adminIds as $adminId) {
        //         $this->sendReply(trim($adminId), null, "Non-admin user tried to interact: \nID: $chatId\nUsername: $userName\nName: $firstName $lastName");
        //     }

        //     // Send message to the non-admin user
        //     $this->sendReply($chatId, $messageId, "You are not authorized to use this bot.");

        //     // Return response after non-admin check
        //     return;
        // }



        // if (isset($message['text'])) {
        //     if ($message['text'] === '/usage') {
        //         $servers = Server::all();
        //         // Prepare the table message in Markdown format
        //         $reply = "*Remark* | *Usage (GB)*\n--- | ---\n";
        //         $totalUsage = 0;
        //         foreach ($servers as $server) {
        //             if ($server->status == "ONLINE") {
        //                 $reply .= "{$server->remark} | *{$server->todayUsage}* \n";
        //                 $totalUsage += $server->todayUsage;
        //             }
        //         }
        //         $reply .= "Total | *{$totalUsage}* \n";
        //         try {
        //             $response = $this->telegram->sendMessage([
        //                 'chat_id' => $chatId,
        //                 'reply_to_message_id' => $messageId,
        //                 'text' => $reply,
        //                 'parse_mode' => 'Markdown'
        //             ]);
        //             return $response;
        //         } catch (\Exception $e) {
        //             Log::error('Telegram API error: ' . $e->getMessage());
        //         }
        //     } else {
        //         $this->sendReply($chatId, $messageId, "No file received.");
        //     }
        // } else {
        //     $this->sendReply($chatId, $messageId, "No text message received.");
        // }

        // $text = $message['text'] ?? '';

        // // Process the message
        // if ($text === '/start') {
        //     $this->sendReply($chatId, $messageId, 'Welcome to the bot!');
        // } else {
        //     $this->sendReply($chatId, $messageId, 'You said: ' . $text);
        // }
    }

    private function handleFreeXrayBotMessage()
    {
        // Implement the handleFreeXrayBotMessage method
        Log::info('Handling FreeXrayBot message.');
        // Add your logic here
        $this->sendReply('Hello from FreeXrayBot!');
        if($this->checkUser(env('TELEGRAM_XADMIN_IDS'))){
            $this->sendReply('You are admin!');
        }
        $message = $this->requestData['message'];
        $reply = '';
        if (isset($message['text'])) {
            switch ($message['text']) {
                case '/Yesterday':
                    $servers = Server::all();
                    // Prepare the table message in Markdown format
                    $reply = "*Remark* | *Usage (GB)*\n--- | ---\n";
                    $totalUsage = 0;
                    foreach ($servers as $server) {
                        if ($server->status == "ONLINE") {
                            $yesterdayUsage = $server->yesterdayUsage;
                            $reply .= "{$server->remark} | *{$yesterdayUsage}* \n";
                            $totalUsage += $yesterdayUsage;
                        }
                    }
                    $reply .= "Total | *{$totalUsage}* \n";
                    break;
                case '/today':
                    $servers = Server::all();
                    // Prepare the table message in Markdown format
                    $reply = "*Remark* | *Usage (GB)*\n--- | ---\n";
                    $totalUsage = 0;
                    foreach ($servers as $server) {
                        if ($server->status == "ONLINE") {
                            $todayUsage = $server->todayUsage;
                            $reply .= "{$server->remark} | *{$todayUsage}* \n";
                            $totalUsage += $todayUsage;
                        }
                    }
                    $reply .= "Total | *{$totalUsage}* \n";
                    break;
                default:
                    $reply = "Received unknown command.";
            }
        } else {    
            $reply = "No text message received.";
        }

        $this->sendReply($reply);

        
    }
    private function checkUser(string $adminIds): bool
    {
        $message = $this->requestData['message'];
        $chatId = $message['chat']['id'];
        $userName = $message['from']['username'] ?? 'Unknown';
        $firstName = $message['from']['first_name'] ?? 'Unknown';
        $lastName = $message['from']['last_name'] ?? 'Unknown';
        $adminIds = explode(',', $adminIds);
        if (!in_array((int) $chatId, $adminIds)) {
            // Notify admins about the unauthorized attempt
            $text = "Non-admin user tried to interact: \nID: $chatId\nUsername: $userName\nName: $firstName $lastName";
            foreach ($adminIds as $adminId) {
                $this->telegram->sendMessage([
                    'chat_id' => $adminId,
                    'text' => $text,
                ]);

                // Send message to the non-admin user

            }
            $this->sendReply("You are not authorized to use this bot.");
            return false;
        } else {
            return True;
        }
    }

    private function sendReply(string $text)
    {
        $message = $this->requestData['message'];
        $chatId = $message['chat']['id'];
        $messageId = $message['message_id'];

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
