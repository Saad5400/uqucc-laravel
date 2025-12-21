<?php

namespace App\Services\Telegram\Handlers;

use Telegram\Bot\Objects\Message;
use Illuminate\Support\Facades\Http;

class DeepSeekChatHandler extends BaseHandler
{
    protected array $messageHistories = [];

    protected int $messageHistoryLimit = 10;

    public function handle(Message $message): void
    {
        $content = trim($message->getText() ?? '');

        // Check if message starts with "اسال سيك"
        if (!preg_match('/^اسال سيك\s+(.+)$/us', $content, $matches)) {
            return;
        }

        $query = trim($matches[1]);
        $this->processQuery($message, $query);
    }

    protected function processQuery(Message $message, string $query): void
    {
        $userId = $message->getFrom()->getId();
        $chatId = $message->getChat()->getId();
        $historyKey = "{$chatId}_{$userId}";

        // Initialize history if needed
        if (!isset($this->messageHistories[$historyKey])) {
            $this->messageHistories[$historyKey] = [];
        }

        // Build user query
        $userName = $message->getFrom()->getFirstName();
        if ($message->getFrom()->getLastName()) {
            $userName .= ' ' . $message->getFrom()->getLastName();
        }
        if ($message->getFrom()->getUsername()) {
            $userName .= ' (@' . $message->getFrom()->getUsername() . ')';
        }

        $userQuery = "{$userName}: {$query}";

        // Add user message to history
        $this->messageHistories[$historyKey][] = [
            'role' => 'user',
            'content' => $userQuery,
        ];

        // Limit history
        if (count($this->messageHistories[$historyKey]) > $this->messageHistoryLimit) {
            $this->messageHistories[$historyKey] = array_slice(
                $this->messageHistories[$historyKey],
                -$this->messageHistoryLimit
            );
        }

        // Prepare messages for API
        $messages = [
            [
                'role' => 'system',
                'content' => 'أنت مساعد بوت تليجرام يُدعى CatoCoder. استخدم فقط plain text ولا تكتب اي نوع من ال markdown.',
            ],
            ...$this->messageHistories[$historyKey],
        ];

        // Send "thinking" message
        $thinkingMessage = $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'جاري المعالجة...',
        ]);

        $response = $this->callDeepSeekAPI($messages);

        if ($response) {
            // Edit the thinking message with the response
            $this->telegram->editMessageText([
                'chat_id' => $chatId,
                'message_id' => $thinkingMessage->getMessageId(),
                'text' => $response,
            ]);

            // Add assistant response to history
            $this->messageHistories[$historyKey][] = [
                'role' => 'assistant',
                'content' => $response,
            ];
        } else {
            $this->telegram->editMessageText([
                'chat_id' => $chatId,
                'message_id' => $thinkingMessage->getMessageId(),
                'text' => 'حدث خطأ أثناء معالجة طلبك.',
            ]);
        }
    }

    protected function callDeepSeekAPI(array $messages): ?string
    {
        $apiKey = config('services.deepseek.token');

        if (empty($apiKey)) {
            return null;
        }

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => "Bearer {$apiKey}",
                    'Content-Type' => 'application/json',
                ])
                ->post('https://api.deepseek.com/v1/chat/completions', [
                    'model' => 'deepseek-chat',
                    'messages' => $messages,
                    'temperature' => 0.7,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                return $data['choices'][0]['message']['content'] ?? 'لم يتم الحصول على رد.';
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }
}
