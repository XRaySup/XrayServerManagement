<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->enum('status',array_keys(\App\Models\Server::stats()))->default('DRAFT')->nullable();
        });
    }

};
