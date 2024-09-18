<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Console\Commands\RunDnsUpdate;
use Telegram\Bot\Api;
use Illuminate\Support\Facades\Log;

class ProcessIpsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 900; // Set timeout to 15 minutes
    protected $chatId;
    protected $chunk;
    protected $progressMessageId;
    protected $chunkIndex;
    protected $totalChunks;

    public function __construct($chunk, $chatId, $progressMessageId, $chunkIndex, $totalChunks)
    {
        $this->chunk = $chunk;
        $this->chatId = $chatId;
        $this->progressMessageId = $progressMessageId;
        $this->chunkIndex = $chunkIndex;
        $this->totalChunks = $totalChunks;
    }

    public function handle()
    {
        try {
            $telegram = new Api(env('TELEGRAM_BOT_TOKEN'));
            // Instantiate the RunDnsUpdate command and process IPs
            $command = app(RunDnsUpdate::class);
            $result = $command->processIps($this->chunk);
            print_r($this->chunk);
            // Calculate progress percentage
            $progress = round(($this->chunkIndex / $this->totalChunks) * 100);

            // Update the progress message on Telegram
            try {

                $telegram->editMessageText([
                    'chat_id' => $this->chatId,
                    'message_id' => $this->progressMessageId,
                    'text' => "Your file is being processed. Progress: {$progress}%",
                ]);
            } catch (\Telegram\Bot\Exceptions\TelegramResponseException $e) {
                Log::error('Telegram API error while updating progress: ' . $e->getMessage());
            }

        } catch (\Exception $e) {
            \Log::error('Failed to process IPs: ' . $e->getMessage());
            // Handle error sending message or logging here
        }
    }
}
