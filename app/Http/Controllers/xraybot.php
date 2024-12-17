<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Telegram\Bot\Laravel\Facades\Telegram;
use Illuminate\Support\Facades\Log;
use App\Models\Server; // Add this line to import the Server model

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
        try {
            $message = $request->input('message');
            $chatId = $message['chat']['id'];
            $messageId = $message['message_id'];
            $userName = $message['from']['username'] ?? 'Unknown';
            $firstName = $message['from']['first_name'] ?? 'Unknown';
            $lastName = $message['from']['last_name'] ?? 'Unknown';

            // Log the received message for debugging
            Log::info('Received message: ', $message);

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

            return response()->json(['status' => 'ok']);
        } catch (\Exception $e) {
            Log::error('Error handling webhook: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
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
