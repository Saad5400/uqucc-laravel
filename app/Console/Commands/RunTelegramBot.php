<?php

namespace App\Console\Commands;

use App\Services\TelegramBotService;
use Illuminate\Console\Command;

class RunTelegramBot extends Command
{
    protected $signature = 'telegram:run
                            {--reset : Reset the offset to 0 before starting}
                            {--status : Show current offset and exit}';

    protected $description = 'Run the Telegram bot';

    public function handle(): int
    {
        if ($this->option('status')) {
            $offset = TelegramBotService::getOffset();
            $this->info("Current offset: {$offset}");

            return self::SUCCESS;
        }

        if ($this->option('reset')) {
            TelegramBotService::resetOffset();
            $this->warn('Offset has been reset to 0');
        }

        $this->info('Starting Telegram bot...');

        $bot = new TelegramBotService;
        $bot->run();

        return self::SUCCESS;
    }
}
