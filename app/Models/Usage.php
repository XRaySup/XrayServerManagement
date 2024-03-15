<?php

namespace App\Models;

//use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Usage extends Model
{
    //use HasFactory;
    protected $fillable = ['server_id','inbound_id','client_id','up','down','upIncrease','downIncrease', 'timestamps'];

}
