<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Console\Commands\RunDnsUpdate;
use App\Http\Controllers\isegarobotController;

class ProcessIpsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public $timeout = 900; // Set timeout to 5 minutes (300 seconds)
    protected $fileContents;
    protected $chatId;

    public function __construct(array $fileContents, string $chatId)
    {
        $this->fileContents = $fileContents;
        $this->chatId = $chatId;
    }

    public function handle(isegarobotController $controller)
    {

        try {
            // Instantiate the RunDnsUpdate command and process IPs
            $command = app(RunDnsUpdate::class);
            $result = $command->processIps($this->fileContents);

            // Send the result back via the bot
            $controller->replyIps($result, $this->chatId);

        } catch (\Exception $e) {
            \Log::error('Failed to process IPs: ' . $e->getMessage());
            $controller->reply("There was an error processing the IPs.", $this->chatId);
        }
    }
}
