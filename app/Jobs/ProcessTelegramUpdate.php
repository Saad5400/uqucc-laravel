<?php

namespace App\Jobs;

use App\Services\Telegram\ContentParser;
use App\Services\Telegram\Handlers\DeepSeekChatHandler;
use App\Services\Telegram\Handlers\EditLinkHandler;
use App\Services\Telegram\Handlers\ExternalSearchHandler;
use App\Services\Telegram\Handlers\HelpHandler;
use App\Services\Telegram\Handlers\InfoHandler;
use App\Services\Telegram\Handlers\InviteLinkHandler;
use App\Services\Telegram\Handlers\JavaExecutionHandler;
use App\Services\Telegram\Handlers\LoginHandler;
use App\Services\Telegram\Handlers\PageManagementHandler;
use App\Services\Telegram\Handlers\PrivateForwardHandler;
use App\Services\Telegram\Handlers\PythonExecutionHandler;
use App\Services\Telegram\Handlers\UquccListHandler;
use App\Services\Telegram\Handlers\UquccSearchHandler;
use App\Services\OgImageService;
use App\Services\QuickResponseService;
use App\Services\TipTapContentExtractor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\Update;

class ProcessTelegramUpdate implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 120;

    /**
     * Create a new job instance.
     *
     * @param  array<string, mixed>  $updateData  The update data as an array
     */
    public function __construct(
        public array $updateData
    ) {
        // Use dedicated queue for telegram updates to enable concurrent processing
        $this->onQueue('telegram');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $telegram = new Api(config('services.telegram.token'), false);
            $update = new Update($this->updateData);

            $this->processUpdate($telegram, $update);
        } catch (\Exception $e) {
            Log::error('ProcessTelegramUpdate job failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
                'update_id' => $this->updateData['update_id'] ?? 'unknown',
            ]);
        }
    }

    /**
     * Process a single Telegram update.
     */
    protected function processUpdate(Api $telegram, Update $update): void
    {
        // Handle callback queries (inline button presses)
        $callbackQuery = $update->getCallbackQuery();
        if ($callbackQuery) {
            try {
                $pageManagementHandler = new PageManagementHandler($telegram, app(ContentParser::class));
                $pageManagementHandler->handleCallback($callbackQuery);
            } catch (\Exception $e) {
                Log::error('Telegram callback error', [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile().':'.$e->getLine(),
                ]);
            }

            return;
        }

        $message = $update->getMessage();

        if (! $message instanceof \Telegram\Bot\Objects\Message) {
            return;
        }

        if ($message->getFrom()?->getIsBot()) {
            return;
        }

        // Initialize handlers
        $handlers = [
            new HelpHandler($telegram),
            new LoginHandler($telegram),
            new PageManagementHandler($telegram, app(ContentParser::class)),
            new EditLinkHandler($telegram),
            new ExternalSearchHandler($telegram), // Priority handler for قوقل and قيم commands
            new UquccSearchHandler($telegram, app(QuickResponseService::class), app(TipTapContentExtractor::class), app(OgImageService::class)),
            new UquccListHandler($telegram),
            new PythonExecutionHandler($telegram),
            new JavaExecutionHandler($telegram),
            new DeepSeekChatHandler($telegram),
            new InfoHandler($telegram),
            new PrivateForwardHandler($telegram),
            new InviteLinkHandler($telegram),
        ];

        foreach ($handlers as $handler) {
            try {
                $handler->handle($message);
            } catch (\Exception $e) {
                Log::error('Telegram handler error', [
                    'handler' => get_class($handler),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile().':'.$e->getLine(),
                ]);
            }
        }
    }
}
