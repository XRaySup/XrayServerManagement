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
    protected $signature = 'process:ip {ip}';

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
         $telegram = Telegram::bot('mybot');
         $message = $telegram->sendMessage([
             'chat_id' => env('TELEGRAM_ADMIN_IDS'),
             'text' => 'job started',
         ]);
         dump($message);
        //$dnsUpdateService->processIp($ipAddress);
        $filecontent = file_get_contents(base_path('Xray/bin/ips.csv'));
        $result = $dnsUpdateService->processFileContent($filecontent,$message);
        dump( $result['message']);
        return 0;
    }
}