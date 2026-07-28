<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_teams', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('chat_id');
            $table->foreignId('category_id')->nullable()->constrained('telegram_team_categories')->nullOnDelete();
            $table->string('name', 64);
            $table->bigInteger('created_by_telegram_id')->nullable();
            $table->timestamps();

            $table->unique(['chat_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_teams');
    }
};
