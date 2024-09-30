<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Console\Commands\RunDnsUpdate;
//use Telegram\Bot\Api;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Laravel\Facades\Telegram;

class ProcessIpsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 900; // Set timeout to 15 minutes
    protected $chatId;
    protected $fileContet;
    protected $progressMessage;


    public function __construct($fileContet, $chatId, $progressMessage)
    {
        $this->fileContet = $fileContet;
        $this->chatId = $chatId;
        $this->progressMessage = $progressMessage;

    }

    public function handle()
    {
        try {
            $telegram = Telegram::bot('mybot');
            // Instantiate the RunDnsUpdate command and process IPs

            $command = new RunDnsUpdate;
            $command->updateTelegramMessageWithRetry($this->progressMessage, "before command.");
            $fileResponse = $command->processFileContent($this->fileContet);

            $progressMessageText = '';
            if ($fileResponse !== null) {

                $progressMessageText .= "\nProcessing file :\n" . $fileResponse['message'];
            }else{
                $progressMessageText .= "\nFile was empty!";
            }

                
                $command->updateTelegramMessageWithRetry($this->progressMessage, $progressMessageText);
    


 
        } catch (\Exception $e) {
            Log::error('Failed to process IPs: ' . $e->getMessage());

        }
    }
}
