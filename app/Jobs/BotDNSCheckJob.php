<?php

namespace App\Jobs;

use App\Services\DnsUpdateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class BotDNSCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $progressMessage;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($progressMessage)
    {
        $this->progressMessage = $progressMessage;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(DnsUpdateService $dnsUpdateService)
    {
        Log::info('BotDNSCheckJob started.');
        $dnsUpdateService->botDNSCheck($this->progressMessage);
        Log::info('BotDNSCheckJob completed.');
    }
}
