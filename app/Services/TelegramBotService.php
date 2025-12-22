<?php

namespace App\Services;

use Telegram\Bot\Api;
use Telegram\Bot\Objects\Update;
use App\Services\Telegram\Handlers\UquccSearchHandler;
use App\Services\Telegram\Handlers\UquccListHandler;
use App\Services\Telegram\Handlers\PythonExecutionHandler;
use App\Services\Telegram\Handlers\JavaExecutionHandler;
use App\Services\Telegram\Handlers\DeepSeekChatHandler;
use App\Services\QuickResponseService;

class TelegramBotService
{
    protected Api $telegram;

    protected array $handlers = [];

    public function __construct()
    {
        $this->telegram = new Api(config('services.telegram.token'));

        // Initialize handlers
        $this->handlers = [
            new UquccSearchHandler($this->telegram, app(QuickResponseService::class)),
            new UquccListHandler($this->telegram),
            new PythonExecutionHandler($this->telegram),
            new JavaExecutionHandler($this->telegram),
            new DeepSeekChatHandler($this->telegram),
        ];
    }

    public function run(): void
    {
        echo "Bot is ready!", PHP_EOL;
        echo "Logged in as " . $this->telegram->getMe()->getFirstName(), PHP_EOL;

        $offset = 0;

        while (true) {
            try {
                $updates = $this->telegram->getUpdates([
                    'offset' => $offset,
                    'timeout' => 30,
                ]);

                foreach ($updates as $update) {
                    $this->handleUpdate($update);
                    $offset = $update->getUpdateId() + 1;
                }
            } catch (\Exception $e) {
                echo "Error: " . $e->getMessage() . PHP_EOL;
                sleep(5);
            }
        }
    }

    protected function handleUpdate(Update $update): void
    {
        $message = $update->getMessage();

        if (!$message || $message->getFrom()->getIsBot()) {
            return;
        }

        foreach ($this->handlers as $handler) {
            $handler->handle($message);
        }
    }
}
