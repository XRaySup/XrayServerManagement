<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessIpsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $fileContents;
    protected $chatId;

    public function __construct($fileContents, $chatId)
    {
        $this->fileContents = $fileContents;
        $this->chatId = $chatId;
    }

    public function handle()
    {
        $controller = app('App\Http\Controllers\isegarobotController');

        $controller->processFileContents($this->fileContents, $this->chatId);
    }
}
