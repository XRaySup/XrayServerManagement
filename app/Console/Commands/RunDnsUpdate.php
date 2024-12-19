<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\DnsUpdateService;

class RunDnsUpdate extends Command
{
    
    protected $signature = 'dns:update';
    protected $description = 'Run the DNS update script';

    public function __construct()
    {
        parent::__construct();

    }

    public function handle()
    {
        $dnsUpdateService = new DnsUpdateService(function ($message) {
            $this->info($message);
        });
        $dnsUpdateService->handle();

    }

}
