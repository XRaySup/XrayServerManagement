<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;

class isegarobotController extends Controller
{
    private $telegramApiUrl = 'https://api.telegram.org/file/bot';


    public function handleWebhook(Request $request)
    {
        
        $botToken = env('TELEGRAM_BOT_TOKEN'); // Get bot token from .env
        $message = $request->input('message');
        
        if (isset($message['audio'])) {
            $fileId = $message['audio']['file_id'];
            $chatId = $message['chat']['id'];
    
            // Get file path from Telegram API using Laravel's Http
            $response = Http::get("https://api.telegram.org/bot{$botToken}/getFile", [
                'file_id' => $fileId,
            ]);
    
            $fileData = $response->json();
    
            if (isset($fileData['result']['file_path'])) {
                $fileUrl = "{$this->telegramApiUrl}{$botToken}/{$fileData['result']['file_path']}";
    
                // Download the file and store it locally or process it
                $fileContents = Http::get($fileUrl)->body();
                $fileName = basename($fileData['result']['file_path']);
                
                Storage::put("telegram_files/{$fileName}", $fileContents);
    
                // Optionally, send a confirmation message to the user
                $this->sendMessage($chatId, "Audio file received: {$fileName}");
            }
        } else {
            // Handle other types of messages or send an error message
            $chatId = $message['chat']['id'];
            $this->sendMessage($chatId, "No audio file received.");
        }
    
        return response()->json(['status' => 'ok']);
    }

    private function sendMessage($chatId, $text)
    {
        $botToken = env('TELEGRAM_BOT_TOKEN'); // Get bot token from .env
        Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $text,
        ]);
    }
}
