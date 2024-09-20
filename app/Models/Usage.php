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
        $batchSize = 100; // Adjust batch size as needed
        $iteration = 0;
    
        do {
            $records = self::select('server_id', 'inbound_id', 'client_id', \DB::raw('DATE(created_at) as date'), \DB::raw('MAX(created_at) as max_created_at'))
                ->groupBy('server_id', 'inbound_id', 'client_id', 'date')
                ->limit($batchSize)
                ->get();
    
            foreach ($records as $record) {
                $deletedCount += self::where('server_id', $record->server_id)
                    ->where('inbound_id', $record->inbound_id)
                    ->where('client_id', $record->client_id)
                    ->whereDate('created_at', $record->date)
                    ->where('created_at', '<', $record->max_created_at)
                    ->delete();
            }
    
            $iteration++;
            echo "Batch $iteration: Deleted $deletedCount records so far...\n"; // Print out progress
        } while ($records->count() === $batchSize); // Continue until fewer records than batch size
    
        return $deletedCount;
    }
}
