<?php

namespace App\Console\Commands;

use App\Services\TelegramBotService;
use Illuminate\Console\Command;

class RunTelegramBot extends Command
{
    protected $signature = 'telegram:run';

    protected $description = 'Run the Telegram bot';

    public function handle(): void
    {
        $this->info('Starting Telegram bot...');

        $bot = new TelegramBotService();
        $bot->run();
    }
}
