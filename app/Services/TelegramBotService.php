<?php

namespace App\Services;

use App\Services\Telegram\ContentParser;
use App\Services\Telegram\Handlers\DeepSeekChatHandler;
use App\Services\Telegram\Handlers\InfoHandler;
use App\Services\Telegram\Handlers\InviteLinkHandler;
use App\Services\Telegram\Handlers\JavaExecutionHandler;
use App\Services\Telegram\Handlers\LoginHandler;
use App\Services\Telegram\Handlers\PageManagementHandler;
use App\Services\Telegram\Handlers\PrivateForwardHandler;
use App\Services\Telegram\Handlers\PythonExecutionHandler;
use App\Services\Telegram\Handlers\UquccListHandler;
use App\Services\Telegram\Handlers\UquccSearchHandler;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\Update;

class TelegramBotService
{
    protected Api $telegram;

    protected array $handlers = [];

    protected PageManagementHandler $pageManagementHandler;

    public function __construct()
    {
        $this->telegram = new Api(config('services.telegram.token'));

        // Initialize page management handler (needs special handling for callbacks)
        $this->pageManagementHandler = new PageManagementHandler($this->telegram, app(ContentParser::class));

        // Initialize handlers
        $this->handlers = [
            new LoginHandler($this->telegram),
            $this->pageManagementHandler,
            new UquccSearchHandler($this->telegram, app(QuickResponseService::class), app(TelegramMarkdownService::class), app(TipTapContentExtractor::class)),
            new UquccListHandler($this->telegram),
            new PythonExecutionHandler($this->telegram),
            new JavaExecutionHandler($this->telegram),
            new DeepSeekChatHandler($this->telegram),
            new InfoHandler($this->telegram),
            new PrivateForwardHandler($this->telegram),
            new InviteLinkHandler($this->telegram),
        ];
    }

    public function run(): void
    {
        echo 'Bot is ready!', PHP_EOL;
        echo 'Logged in as '.$this->telegram->getMe()->getFirstName(), PHP_EOL;

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
                echo 'Error: '.$e->getMessage().PHP_EOL;
                echo 'File: '.$e->getFile().':'.$e->getLine().PHP_EOL;
                echo 'Trace: '.$e->getTraceAsString().PHP_EOL;
                sleep(5);
            }
        }
    }

    protected function handleUpdate(Update $update): void
    {
        try {
            // Handle callback queries (inline button presses)
            $callbackQuery = $update->getCallbackQuery();
            if ($callbackQuery) {
                try {
                    $this->pageManagementHandler->handleCallback($callbackQuery);
                } catch (\Exception $e) {
                    echo 'Callback error: '.$e->getMessage().PHP_EOL;
                    echo 'File: '.$e->getFile().':'.$e->getLine().PHP_EOL;
                }

                return;
            }

            $message = $update->getMessage();

            if (! $message || $message->getFrom()->getIsBot()) {
                return;
            }

            foreach ($this->handlers as $handler) {
                try {
                    $handler->handle($message);
                } catch (\Exception $e) {
                    echo 'Handler error: '.get_class($handler).' - '.$e->getMessage().PHP_EOL;
                    echo 'File: '.$e->getFile().':'.$e->getLine().PHP_EOL;
                }
            }
        } catch (\Exception $e) {
            echo 'Update handling error: '.$e->getMessage().PHP_EOL;
            echo 'File: '.$e->getFile().':'.$e->getLine().PHP_EOL;
        }
    }
}
