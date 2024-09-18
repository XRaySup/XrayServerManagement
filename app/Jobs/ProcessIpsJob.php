<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Console\Commands\RunDnsUpdate;
use App\Http\Controllers\isegarobotController;
use Telegram\Bot\Api;
use Illuminate\Support\Facades\Log;

class ProcessIpsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public $timeout = 900; // Set timeout to 5 minutes (300 seconds)
    protected $fileContents;
    protected $chatId;
    protected $chunk;
    protected $progressMessageId;
    protected $index;
    protected $totalChunks;

    public function __construct($chunk, $chatId, $progressMessageId, $index, $totalChunks)
    {
        $this->chunk = $chunk;
        $this->chatId = $chatId;
        $this->progressMessageId = $progressMessageId;
        $this->index = $index;
        $this->totalChunks = $totalChunks;
    }

    public function handle(isegarobotController $controller)
    {

        try {
            // Instantiate the RunDnsUpdate command and process IPs
            $command = app(RunDnsUpdate::class);
            $result = $command->processIps($this->fileContents);
        // Calculate progress percentage
        $progress = round(($this->index / $this->totalChunks) * 100);

        // Update the progress message on Telegram
        try {
            $telegram = new Api(env('TELEGRAM_BOT_TOKEN'));
            $telegram->editMessageText([
                'chat_id' => $this->chatId,
                'message_id' => $this->progressMessageId,
                'text' => "Your file is being processed. Progress: {$progress}%",
            ]);
        } catch (\Telegram\Bot\Exceptions\TelegramResponseException $e) {
            Log::error('Telegram API error while updating progress: ' . $e->getMessage());
        }
            // Send the result back via the bot
            //$controller->replyIps($result, $this->chatId);

        } catch (\Exception $e) {
            \Log::error('Failed to process IPs: ' . $e->getMessage());
            $controller->reply("There was an error processing the IPs.", $this->chatId);
        }
    }
}
