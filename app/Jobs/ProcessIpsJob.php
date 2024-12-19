<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Laravel\Facades\Telegram;
use App\Services\DnsUpdateService;

class ProcessIpsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $fileContents;
    protected $progressMessage;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($fileContents, $progressMessage)
    {
        $this->fileContents = $fileContents;
        $this->progressMessage = $progressMessage;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        Log::info('ProcessIpsJob started.');
        Log::info('File contents: ' . print_r($this->fileContents, true));
        Log::info('Progress message: ' . $this->progressMessage);

        // Add your processing logic here

        Log::info('ProcessIpsJob completed.');
    }
}
