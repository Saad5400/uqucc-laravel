<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_team_categories', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('chat_id');
            $table->string('name', 64);
            $table->timestamps();

            $table->unique(['chat_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_team_categories');
    }
};
