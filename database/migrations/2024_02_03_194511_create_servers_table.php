<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('address');
            $table->string('ipv4');
            $table->string('ipv6')->nullable();
            $table->string('ssh_user')->nullable();
            $table->string('ssh_password')->nullable();
            $table->unsignedBigInteger('xui_port')->default(2052)->nullable();
            $table->string('xui_username')->nullable();
            $table->string('xui_password')->nullable();
            $table->foreignIdFor(\App\Models\Owner::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(\App\Models\Project::class)->nullable()->constrained()->nullOnDelete();
            $table->string('domain')->nullable();
            $table->json('tags')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
