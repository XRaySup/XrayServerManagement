<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Illuminate\Support\Facades\Storage;

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

            try {
                $file = $this->telegram->getFile(['file_id' => $fileId]);
                $filePath = $file->getFilePath();
                $fileUrl = "https://api.telegram.org/file/bot" . env('TELEGRAM_BOT_TOKEN') . "/$filePath";

                // Download the file
                $fileContents = file_get_contents($fileUrl);
                $fileName = basename($filePath);
                Storage::put("telegram_files/{$fileName}", $fileContents);

                // Send a confirmation message
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "File received: {$fileName}"
                ]);
            } catch (TelegramSDKException $e) {
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "Error: {$e->getMessage()}"
                ]);
            }
        } else {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "No file received."
            ]);
        }

        return response()->json(['status' => 'ok']);
    }
}
