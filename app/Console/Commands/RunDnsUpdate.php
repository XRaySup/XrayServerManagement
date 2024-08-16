<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

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
        // Path to the script and arguments
        $scriptPath = base_path('scripts/DNSUpdate.sh');
        $subdomainPattern = 'bpb.yousef.isegaro.org'; // Adjust this if necessary
        $csvFile = base_path('results.csv');

        // Fetch environment variables
        $zoneId = env('CLOUDFLARE_ZONE_ID');
        $apiToken = env('CLOUDFLARE_API_TOKEN');

        if (empty($zoneId) || empty($apiToken)) {
            $this->error('Cloudflare Zone ID or API Token is not set.');
            return;
        }

        // Prepare the command
        $command = escapeshellcmd("bash $scriptPath $subdomainPattern $zoneId $apiToken $csvFile");

        // Execute the command
        $output = [];
        $returnVar = 0;

        exec($command, $output, $returnVar);

        // Log output and errors
        foreach ($output as $line) {
            $this->info($line);
        }

        if ($returnVar !== 0) {
            $this->error("Script execution failed with status code $returnVar.");
        } else {
            $this->info("Script executed successfully. Check $csvFile for results.");
        }
    }
}
