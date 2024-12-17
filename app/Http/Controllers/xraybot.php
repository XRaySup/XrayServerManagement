<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Telegram\Bot\Laravel\Facades\Telegram;
use Illuminate\Support\Facades\Log;

class xraybot extends Controller
{
    private $telegram;

    public function __construct()
    {
        $this->telegram = Telegram::bot('FreeXrayBot');
    }

    /**
     * Handle the Telegram webhook.
     */
    public function handleWebhook(Request $request)
    {
        $message = $request->input('message');
        $chatId = $message['chat']['id'];
        $messageId = $message['message_id'];
        $userName = $message['from']['username'] ?? 'Unknown';
        $firstName = $message['from']['first_name'] ?? 'Unknown';
        $lastName = $message['from']['last_name'] ?? 'Unknown';

        $adminIds = explode(',', env('TELEGRAM_XADMIN_IDS'));
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
        if (isset($message['text'])) {
            if ($message['text'] === 'Run') {
                $servers = Server::all();
                // Prepare the table message in Markdown format
                $message = "
        *Remark* | *Usage (GB)*
        --- | ---
        ";
                foreach ($servers as $server) {
                    if ($server->status == "ONLINE") {
                        $message .= "{$server->remark} | *{$server->todayUsage}* \n";
                    }
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
        } catch (\Exception $e) {
            Log::error('Telegram API error: ' . $e->getMessage());
        }
    }


}
