<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Jobs\HandleTelegramMessage;

class TelegramWebhookController extends Controller
{
    public function handle(Request $request)
    {
        try {
            // Extract necessary data from the request
            $requestData = $request->all();

            // Dispatch the job to handle the message
            // Log::info('TelegramWebhookController: handle');
            Log::info('Request: ' . print_r($requestData, true));
            HandleTelegramMessage::dispatch($requestData);

            return response()->json(['status' => 'ok']);
        } catch (\Exception $e) {
            Log::error('Error handling webhook: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
