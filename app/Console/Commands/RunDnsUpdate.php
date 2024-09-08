<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Yaza\LaravelGoogleDriveStorage\Gdrive;
use Illuminate\Support\Facades\File;
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
        $subdomainPattern = 'bpb.yousef.isegaro.com'; // Adjust this if necessary
        $csvFile = base_path('storage/logs/'.$subdomainPattern.'.csv');

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
        $csv = File::get($csvFile, 10);
        Storage::disk('google')->put('DNSUpdate/'.$subdomainPattern, $csv, ['visibility' => "public"]);
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
