<?php

use App\Helpers\ArabicNormalizer;
use App\Models\TelegramTeam;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_teams', function (Blueprint $table) {
            $table->string('normalized_name', 64)->nullable()->after('name');

            $table->index(['chat_id', 'normalized_name']);
        });

        TelegramTeam::query()->eachById(function (TelegramTeam $team): void {
            $team->updateQuietly(['normalized_name' => ArabicNormalizer::normalize($team->name)]);
        });

        Schema::create('telegram_team_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('telegram_teams')->cascadeOnDelete();
            $table->bigInteger('chat_id');
            $table->string('name', 64);
            $table->string('normalized_name', 64);
            $table->timestamps();

            $table->unique(['chat_id', 'normalized_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_team_aliases');

        Schema::table('telegram_teams', function (Blueprint $table) {
            $table->dropIndex(['chat_id', 'normalized_name']);
            $table->dropColumn('normalized_name');
        });
    }
};
