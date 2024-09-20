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
        $deletedCount = 0;
    
        $records = self::select('server_id', 'inbound_id', 'client_id', \DB::raw('DATE(created_at) as date'), \DB::raw('MAX(created_at) as max_created_at'))
            ->groupBy('server_id', 'inbound_id', 'client_id', 'date')
            ->get();
    
        foreach ($records as $record) {
            // Delete older duplicates for the same day
            $duplicates = self::where('server_id', $record->server_id)
                ->where('inbound_id', $record->inbound_id)
                ->where('client_id', $record->client_id)
                ->whereDate('created_at', $record->date)
                ->where('created_at', '<', $record->max_created_at)
                ->get();
    
            foreach ($duplicates as $duplicate) {
                $duplicate->delete(); // Delete each duplicate individually
                $deletedCount++;
            }
            
            echo "Deleted $deletedCount records so far...\n"; // Print out progress
        }
    
        return $deletedCount;
    }
    
}
