<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
//use App\Console\Commands\RunDnsUpdate;
//use Telegram\Bot\Api;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Laravel\Facades\Telegram;
use App\Services\DnsUpdateService;

class ProcessIpsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 900; // Set timeout to 15 minutes
    //protected $chatId;
    protected $fileContent;
    protected $progressMessage;


    public function __construct($fileContent, $progressMessage)
    {
        $this->fileContent = $fileContent;
        //$this->chatId = $chatId;
        $this->progressMessage = $progressMessage;

    }

    public function handle(DnsUpdateService $dnsUpdateService)
    {
        //log::info('job started');
        try {
            // $telegram = Telegram::bot('mybot');
            // // Instantiate the RunDnsUpdate command and process IPs
            // $telegram->sendMessage([
            //     'chat_id' => $this->chatId,
            //     'text' => 'job started',
            // ]);
            
            //$dnsUpdateService->updateTelegramMessageWithRetry($this->progressMessage, "Before process.");
            $fileResponse = $dnsUpdateService->processFileContent($this->fileContent);
            //$dnsUpdateService->updateTelegramMessageWithRetry($this->progressMessage, "After Process.");

            $progressMessageText = '';
            if ($fileResponse !== null) {

                $progressMessageText .= "\nProcessing file :\n" . $fileResponse['message'];
            }else{
                $progressMessageText .= "\nFile was empty!";
            }

                
            $dnsUpdateService->updateTelegramMessageWithRetry($this->progressMessage, $progressMessageText);
    


 
        } catch (\Exception $e) {
            Log::error('Failed to process IPs: ' . $e->getMessage());

        }
    }
}
