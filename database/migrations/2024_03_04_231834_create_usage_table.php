<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        //Schema::dropIfExists('usages');
        Schema::create('usages', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(\App\Models\Server::class,'server_id')->constrained();
            $table->integer('inbound_id');
            $table->integer('client_id')->nullable();             
            $table->float('up');
            $table->float('down');
            $table->float('upIncrease');
            $table->float('downIncrease');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('usages');
    }
};
