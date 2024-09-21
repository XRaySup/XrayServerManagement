<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Usage;

class CleanUpUsages extends Command
{
    protected $signature = 'usage:cleanup';
    protected $description = 'Clean up Usage records to keep only one record per day for each client, inbound, and server.';

    public function handle()
    {
        \DB::statement('PRAGMA busy_timeout = 60000'); // Set timeout for locked database
        \DB::statement('PRAGMA journal_mode = WAL'); // Use Write-Ahead Logging
    
        $deletedCount = 0;
    
        // Get the unique records for cleanup
        $records = Usage::select('server_id', 'inbound_id', 'client_id', \DB::raw('DATE(created_at) as date'), \DB::raw('MAX(created_at) as max_created_at'))
            ->groupBy('server_id', 'inbound_id', 'client_id', 'date')
            ->get();
    
        foreach ($records as $record) {
            // Get the last record for the current group
            $lastRecord = Usage::where('server_id', $record->server_id)
                ->where('inbound_id', $record->inbound_id)
                ->where('client_id', $record->client_id)
                ->whereDate('created_at', $record->date)
                ->orderBy('created_at', 'desc')
                ->first();
    
            // Fetch duplicates
            $duplicates = Usage::where('server_id', $record->server_id)
                ->where('inbound_id', $record->inbound_id)
                ->where('client_id', $record->client_id)
                ->whereDate('created_at', $record->date)
                ->where('created_at', '<', $lastRecord->created_at) // Exclude the last record
                ->get();
    
            if ($duplicates->isNotEmpty()) {
                try {
                    \DB::beginTransaction();
    
                    // Calculate increases based on the last record
                    $upIncrease = $lastRecord->up; // Set the initial value from the last record
                    $downIncrease = $lastRecord->down; // Set the initial value from the last record
    
                    foreach ($duplicates as $duplicate) {
                        $upIncrease += $duplicate->up; // Update the increase
                        $downIncrease += $duplicate->down; // Update the increase
                    }
    
                    // Update the last record with new increase values
                    if ($lastRecord) {
                        $lastRecord->upIncrease = $upIncrease;
                        $lastRecord->downIncrease = $downIncrease;
                        $lastRecord->save();
                    }
    
                    // Delete duplicates
                    $deletedCount += $duplicates->count();
                    $duplicates->each->delete();
    
                    \DB::commit();
    
                    if ($deletedCount % 20 == 0) {
                        $this->info("Deleted {$deletedCount} records so far...");
                    }
                } catch (\Exception $e) {
                    \DB::rollBack();
                    $this->error("Error processing records for server {$record->server_id}, inbound {$record->inbound_id}, client {$record->client_id}: {$e->getMessage()}");
                }
            }
        }
    
        $this->info("Total deleted records: {$deletedCount}");
    }
}
