<?php

namespace App\Jobs;

use App\Services\DnsUpdateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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

        // Instantiate DnsUpdateService within the handle method
        $dnsUpdateService = new DnsUpdateService(function ($message) {
            Log::info($message);
        });

        // Process the file content using DnsUpdateService
        $dnsUpdateService->processFileContent($this->fileContents, $this->progressMessage);

        Log::info('ProcessIpsJob completed.');
    }
}
