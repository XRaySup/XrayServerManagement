<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->json('inboundStat')->nullable()->after('sessionCookie'); // Adjust the data type if needed
        });
    }

    public function down()
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('inboundStat');
        });
    }
};