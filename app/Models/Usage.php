<?php

namespace App\Models;

//use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Usage extends Model
{
    //use HasFactory;
    protected $fillable = ['server_id','inbound_id','client_id','up','down','upIncrease','downIncrease', 'timestamps'];
    public static function cleanUpAllRecords()
    {
        \DB::statement('PRAGMA busy_timeout = 60000'); // Allow more time for locking
    
        $deletedCount = 0;
    
        $records = self::select('server_id', 'inbound_id', 'client_id', \DB::raw('DATE(created_at) as date'), \DB::raw('MAX(created_at) as max_created_at'))
            ->groupBy('server_id', 'inbound_id', 'client_id', 'date')
            ->get();
    
        foreach ($records as $record) {
            // Fetch duplicates
            $duplicates = self::where('server_id', $record->server_id)
                ->where('inbound_id', $record->inbound_id)
                ->where('client_id', $record->client_id)
                ->whereDate('created_at', $record->date)
                ->where('created_at', '<', $record->max_created_at)
                ->get();
    
            foreach ($duplicates as $duplicate) {
                try {
                    $duplicate->delete(); // Delete each duplicate
                    $deletedCount++;
                    echo "Deleted record ID: {$duplicate->id}\n"; // Print deleted record ID
                    sleep(1); // Introduce a slight delay
                } catch (\Exception $e) {
                    echo "Error deleting record ID: {$duplicate->id}, Error: {$e->getMessage()}\n";
                }
            }
        }
    
        return $deletedCount;
    }
    
}
