<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Telegram\Bot\Laravel\Facades\Telegram;
use App\Models\Server;

class HelloWorldCommand extends Command
{
    protected $signature = 'xray:test'; // Command signature

    protected $name = "helloworld"; // Command name
    protected $description = "Sends a Hello World message to the admin"; // Command description

    public function handle()
    {
        // Replace with your admin chat ID
        $adminChatId = env('TELEGRAM_XADMIN_IDS');
        //dump($adminChatId);
        $telegram = Telegram::bot('FreeXrayBot');
        $servers = Server::all();
        //dump($servers);
        // Prepare the table message in Markdown format
        $message = "
*Remark* | *Usage (GB)*
--- | ---
";
$totalUsage = 0;
        foreach ($servers as $server) {
            if ($server->status == "ONLINE") {
                $message .= "{$server->remark} | *{$server->todayUsage}* \n";
                $totalUsage += $server->todayUsage;
            }
        }
        $message .= "Total | *{$totalUsage}* \n";
        // Send the message to the admin
        $telegram->sendMessage([
            'chat_id' => $adminChatId,
            'text' => $message,
            'parse_mode' => 'Markdown'
        ]);
    }

}
