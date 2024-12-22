<?php

namespace App\Console\Commands;
use Telegram\Bot\Laravel\Facades\Telegram;
use Illuminate\Console\Command;
use App\Services\DnsUpdateService;

class ProcessIpCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'process:ip';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process a given IP address';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // $ipAddress = $this->argument('ip');
         $dnsUpdateService = new DnsUpdateService(function ($message) {
             $this->info($message);
         });
         $telegram = Telegram::bot('Proxy');
         $message = $telegram->sendMessage([
             'chat_id' => env('TELEGRAM_TEST_ADMIN_IDS'),
             'text' => 'job started',
         ]);

        //$dnsUpdateService->processIp($ipAddress);
        $filecontent = file_get_contents(base_path('Xray/bin/ips.csv'));
        $dnsUpdateService->processFileContent($filecontent,$message);
        return 0;
    }
}