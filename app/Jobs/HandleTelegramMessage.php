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
use Illuminate\Support\Facades\Http;
use App\Services\DnsUpdateService;

class HandleTelegramMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1200; // Set the timeout to 120 seconds (2 minutes)

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
            case 'Servers':
                $this->telegram = Telegram::bot($this->botIdentifier);
                $this->handleServersBotMessage();
                break;
            case 'Proxy':
                $this->telegram = Telegram::bot($this->botIdentifier);
                $this->handleProxyBotMessage();
                break;
            case 'Test':
                $this->telegram = Telegram::bot($this->botIdentifier);
                $this->handleTestBotMessage();
                break;
            default:
                Log::error('Unknown bot: ' . $this->botIdentifier);
        }
        Log::info('HandleTelegramMessage job completed.');
    }

    private function handleServersBotMessage()
    {
        // Implement the handleFreeXrayBotMessage method
        Log::info('Handling ServersBot message.');

        if ($this->checkUser(env('TELEGRAM_SERVERS_ADMIN_IDS')) == false) {
            return;
        }
        $message = $this->requestData['message'];
        $reply = '';
        if (isset($message['text'])) {
            switch ($message['text']) {
                case '/yesterday':
                    $servers = Server::all();
                    // Prepare the table message in Markdown format
                    $reply = "*Yesterday* \n";
                    $reply .= "*Remark* | *Usage (GB)*\n--- | ---\n";
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
                    $reply = "*Today* \n";
                    $reply .= "*Remark* | *Usage (GB)*\n--- | ---\n";
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

    private function handleProxyBotMessage()
    {
        Log::info('Handling ProxyBot message.');
        // Add your logic here
        //$this->sendReply('Hello from FreeXrayBot!');
        if ($this->checkUser(env('TELEGRAM_PROXY_ADMIN_IDS')) == false) {
            return;
        }
        $message = $this->requestData['message'];
        //$message = $request->input('message');
        // $chatId = $message['chat']['id'];
        // $messageId = $message['message_id'];
        // Continue if the user is an admin
        if (isset($message['document'])) {
            $fileId = $message['document']['file_id'];

            // Send initial message about processing start
            $initialReply = "Your file is being processed.";
            $progressMessage = $this->sendReply($initialReply);

            if (!$progressMessage) {
                return response()->json(['status' => 'error', 'message' => 'Failed to send initial reply'], 500);
            }

            try {

                // Download the file from Telegram
                $fileContents = Http::get("https://api.telegram.org/file/bot" . $this->telegram->getAccessToken() . "/" . $this->telegram->getFile(['file_id' => $fileId])->getFilePath())->body();
                // Dispatch a single job with the entire file contents
                ProcessIpsJob::dispatch($fileContents, $progressMessage);
                //$progressMessage = $this->sendReply($chatId, $messageId, 'test');

            } catch (\Telegram\Bot\Exceptions\TelegramResponseException $e) {
                Log::error('Telegram API error: ' . $e->getMessage());
                $this->sendReply("Error: {$e->getMessage()}");
            } catch (\Exception $e) {
                Log::error('General error: ' . $e->getMessage());
                $this->sendReply("Error: {$e->getMessage()}");
            }
        } elseif (isset($message['text'])) {
            // Check if the text is a valid IP address
            $text = $message['text'];
            if (filter_var($text, FILTER_VALIDATE_IP)) {
                // Pass the IP to the DnsUpdateService to check a single IP
                $dnsUpdateService = new DnsUpdateService();
                $result = $dnsUpdateService->checkSingleIP($text);

                // Send the result back to the user
                $this->sendReply("Result for IP $text: $result");
            } else {
                switch ($text) {
                    case '/dns400check':

                        // Send initial message about processing start
                        $dnsUpdateService = new DnsUpdateService();
                        $initialReply = "Checking $dnsUpdateService->subdomainPattern :";
                        $progressMessage = $this->sendReply($initialReply);
                        $dnsUpdateService->messages = [$progressMessage];
                        $progressMessageText = $dnsUpdateService->DNSCheck();
                        //$this->updateTelegramMessageWithRetry($progressMessage, $progressMessageText);
                        break;
                    default:

                        $this->sendReply("Unknown command.");
                }
            }

        } else {
            $this->sendReply("Unknown command.");
        }
    }
    private function handleTestBotMessage()
    {
        Log::info('Handling ProxyBot message.');
        // Add your logic here
        $this->sendReply('Hello from TestBot!');
        if ($this->checkUser(env('TELEGRAM_PROXY_ADMIN_IDS')) == false) {
            return;
        }
        $message = $this->requestData['message'];

        // Continue if the user is an admin
        if (isset($message['document'])) {
            $fileId = $message['document']['file_id'];

            // Send initial message about processing start
            $initialReply = "Your file is being processed.";
            $progressMessage = $this->sendReply($initialReply);

            if (!$progressMessage) {
                return response()->json(['status' => 'error', 'message' => 'Failed to send initial reply'], 500);
            }

            try {

                // Download the file from Telegram
                $fileContents = Http::get("https://api.telegram.org/file/bot" . $this->telegram->getAccessToken() . "/" . $this->telegram->getFile(['file_id' => $fileId])->getFilePath())->body();
                // Dispatch a single job with the entire file contents
                ProcessIpsJob::dispatch($fileContents, $progressMessage);
                //$progressMessage = $this->sendReply($chatId, $messageId, 'test');

            } catch (\Telegram\Bot\Exceptions\TelegramResponseException $e) {
                Log::error('Telegram API error: ' . $e->getMessage());
                $this->sendReply("Error: {$e->getMessage()}");
            } catch (\Exception $e) {
                Log::error('General error: ' . $e->getMessage());
                $this->sendReply("Error: {$e->getMessage()}");
            }
        } elseif (isset($message['text'])) {
            switch ($message['text']) {
                case '/dns400check':

                    // Send initial message about processing start
                    $dnsUpdateService = new DnsUpdateService();
                    $initialReply = "Checking $dnsUpdateService->subdomainPattern :";
                    $progressMessage = $this->sendReply($initialReply);
                    $progressMessageText = $dnsUpdateService->subdomainPattern . $dnsUpdateService->DNSCheck();
                    $this->updateTelegramMessageWithRetry($progressMessage, $progressMessageText);
                    break;
                default:
                    $this->sendReply("Unknown command.");
            }

        } else {
            $this->sendReply("Unknown command.");
        }
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
                'parse_mode' => 'Markdown',
            ]);
            return $response;
        } catch (\Exception $e) {
            Log::error('Telegram API error: ' . $e->getMessage());
        }
    }
    public function updateTelegramMessageWithRetry($message, $text, $maxRetries = 3)
    {
        //$telegram = Telegram::bot('mybot'); // Initialize the Telegram bot

        // Extract chat ID and message ID from the message object
        $chatId = $message->getChat()->getId();
        $messageId = $message->getMessageId();

        $retryCount = 0;
        $success = false;

        while (!$success && $retryCount < $maxRetries) {
            try {
                // Update the message using the Telegram API
                $this->telegram->editMessageText([
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    'text' => $text,
                ]);
                $success = true; // Exit the loop if successful
            } catch (\Telegram\Bot\Exceptions\TelegramResponseException $e) {
                $retryCount++;
                Log::info("Telegram API error while updating message (attempt $retryCount): " . $e->getMessage());

                // Retry if it's a rate limit error or a recoverable error
                if ($e->getCode() == 429 || $retryCount < $maxRetries) {
                    sleep(1); // Wait before retrying
                } else {
                    break; // Exit loop if it's an unrecoverable error
                }
            }
        }

        if (!$success) {
            Log::info("Failed to update message on Telegram after {$maxRetries} attempts.");
        }

        return $success;
    }
}
