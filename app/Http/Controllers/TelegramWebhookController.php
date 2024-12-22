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
            // Dispatch the job to handle the message
            log::info('TelegramWebhookController: handle');
            log::info  ('Request: ' . print_r($request->all(), true));
            HandleTelegramMessage::dispatch($request);

            return response()->json(['status' => 'ok']);
        } catch (\Exception $e) {
            Log::error('Error handling webhook: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
