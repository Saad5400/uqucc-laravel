<?php

namespace App\Services\Telegram\Handlers;

use App\Services\TelegramMarkdownService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Objects\Message;

class DeepSeekChatHandler extends BaseHandler
{
    protected array $messageHistories = [];

    protected int $messageHistoryLimit = 10;

    protected int $telegramMessageLimit = 4096;

    protected int $editDelayMs = 1000;

    protected int $minCharsBeforeEdit = 50;

    public function handle(Message $message): void
    {
        $text = $message->getText();
        $content = is_string($text) ? trim($text) : '';

        if (! preg_match('/^اسال سيك\s+(.+)$/us', $content, $matches)) {
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

        if (! isset($this->messageHistories[$historyKey])) {
            $this->messageHistories[$historyKey] = [];
        }

        $firstName = $message->getFrom()->getFirstName();
        $userName = is_string($firstName) ? $firstName : (string) $firstName;

        $lastName = $message->getFrom()->getLastName();
        if ($lastName) {
            $userName .= ' '.(is_string($lastName) ? $lastName : (string) $lastName);
        }

        $username = $message->getFrom()->getUsername();
        if ($username) {
            $userName .= ' (@'.(is_string($username) ? $username : (string) $username).')';
        }

        $userQuery = "{$userName}: {$query}";

        $this->messageHistories[$historyKey][] = [
            'role' => 'user',
            'content' => $userQuery,
        ];

        if (count($this->messageHistories[$historyKey]) > $this->messageHistoryLimit) {
            $this->messageHistories[$historyKey] = array_slice(
                $this->messageHistories[$historyKey],
                -$this->messageHistoryLimit
            );
        }

        $messages = [
            [
                'role' => 'system',
                'content' => 'أنت مساعد بوت تليجرام يُدعى CatoCoder. يمكنك استخدام markdown للتنسيق عند الحاجة.',
            ],
            ...$this->messageHistories[$historyKey],
        ];

        $thinkingMessage = $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'جاري المعالجة...',
        ]);

        $response = $this->streamDeepSeekAPI($messages, $chatId, $thinkingMessage->getMessageId());

        if ($response) {
            $this->messageHistories[$historyKey][] = [
                'role' => 'assistant',
                'content' => $response,
            ];
        }
    }

    protected function streamDeepSeekAPI(array $messages, int|string $chatId, int $messageId): ?string
    {
        $apiKey = config('services.deepseek.token');

        if (empty($apiKey)) {
            $this->editMessage($chatId, $messageId, 'حدث خطأ: مفتاح API غير موجود.');

            return null;
        }

        try {
            $response = Http::timeout(120)
                ->withHeaders([
                    'Authorization' => "Bearer {$apiKey}",
                    'Content-Type' => 'application/json',
                    'Accept' => 'text/event-stream',
                ])
                ->withOptions(['stream' => true])
                ->post('https://api.deepseek.com/v1/chat/completions', [
                    'model' => 'deepseek-chat',
                    'messages' => $messages,
                    'temperature' => 0.7,
                    'stream' => true,
                ]);

            if (! $response->successful()) {
                $this->editMessage($chatId, $messageId, 'حدث خطأ أثناء معالجة طلبك.');

                return null;
            }

            $fullContent = '';
            $lastEditContent = '';
            $lastEditTime = 0;
            $buffer = '';

            $body = $response->getBody();

            while (! $body->eof()) {
                $chunk = $body->read(1024);
                $buffer .= $chunk;

                while (($newlinePos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $newlinePos);
                    $buffer = substr($buffer, $newlinePos + 1);

                    $line = trim($line);

                    if (empty($line)) {
                        continue;
                    }

                    if (! str_starts_with($line, 'data: ')) {
                        continue;
                    }

                    $jsonData = substr($line, 6);

                    if ($jsonData === '[DONE]') {
                        break 2;
                    }

                    $data = json_decode($jsonData, true);

                    if (! $data || ! isset($data['choices'][0]['delta']['content'])) {
                        continue;
                    }

                    $deltaContent = $data['choices'][0]['delta']['content'];
                    $fullContent .= $deltaContent;

                    $currentTime = $this->getCurrentTimeMs();
                    $timeSinceLastEdit = $currentTime - $lastEditTime;
                    $contentDiff = strlen($fullContent) - strlen($lastEditContent);

                    if ($timeSinceLastEdit >= $this->editDelayMs && $contentDiff >= $this->minCharsBeforeEdit) {
                        $displayContent = $this->truncateForTelegram($fullContent.'...');
                        $this->editMessage($chatId, $messageId, $displayContent);
                        $lastEditContent = $fullContent;
                        $lastEditTime = $currentTime;
                    }
                }
            }

            if (empty($fullContent)) {
                $this->editMessage($chatId, $messageId, 'لم يتم الحصول على رد.');

                return null;
            }

            // After streaming ends, send the final message with markdown handling
            $finalContent = $this->truncateForTelegram($fullContent);
            if ($finalContent !== $lastEditContent) {
                $this->sendFinalMessage($chatId, $messageId, $finalContent);
            }

            return $fullContent;

        } catch (\Exception $e) {
            Log::error('DeepSeek streaming error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ]);
            $this->editMessage($chatId, $messageId, 'حدث خطأ أثناء معالجة طلبك.');

            return null;
        }
    }

    protected function editMessage(int|string $chatId, int $messageId, string $text): void
    {
        try {
            $this->telegram->editMessageText([
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $text,
            ]);
        } catch (\Exception $e) {
            // Telegram may throw "message is not modified" error - ignore it
            if (! str_contains($e->getMessage(), 'message is not modified')) {
                Log::warning('Failed to edit Telegram message', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    protected function truncateForTelegram(string $text): string
    {
        if (mb_strlen($text) <= $this->telegramMessageLimit) {
            return $text;
        }

        $truncated = mb_substr($text, 0, $this->telegramMessageLimit - 20);
        $lastSpace = mb_strrpos($truncated, ' ');

        if ($lastSpace !== false && $lastSpace > $this->telegramMessageLimit - 100) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }

        return $truncated."\n\n... (تم اقتطاع الرد)";
    }

    protected function getCurrentTimeMs(): int
    {
        return (int) (microtime(true) * 1000);
    }

    /**
     * Strip all markdown formatting from text, leaving only plain text.
     */
    protected function stripMarkdown(string $text): string
    {
        // Remove code blocks (triple backticks)
        $text = preg_replace('/```[a-zA-Z0-9+_-]*\n?[\s\S]*?```/s', '', $text);

        // Remove inline code (single backticks)
        $text = preg_replace('/`([^`]+)`/', '$1', $text);

        // Remove bold (**text** or __text__)
        $text = preg_replace('/\*\*(.+?)\*\*/', '$1', $text);
        $text = preg_replace('/__(.+?)__/', '$1', $text);

        // Remove italic (*text* or _text_)
        $text = preg_replace('/(?<!\*)\*([^*\n]+?)\*(?!\*)/', '$1', $text);
        $text = preg_replace('/(?<!_)_([^_\n]+?)_(?!_)/', '$1', $text);

        // Remove strikethrough (~text~)
        $text = preg_replace('/~([^~]+)~/', '$1', $text);

        // Remove links [text](url) - keep only the text
        $text = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $text);

        // Remove blockquotes (> text)
        $text = preg_replace('/^>\s+(.+)$/m', '$1', $text);

        // Remove HTML tags (in case any exist)
        $text = strip_tags($text);

        return $text;
    }

    /**
     * Send final message with markdown handling and fallbacks.
     * 1. Try to send with MarkdownV2 formatting
     * 2. If that fails, strip markdown and send plain text
     * 3. If that fails, send original text as-is
     */
    protected function sendFinalMessage(int|string $chatId, int $messageId, string $content): void
    {
        // First, try to convert to MarkdownV2 and send
        try {
            $markdownService = new TelegramMarkdownService;
            $formattedContent = $markdownService->toMarkdownV2($content);

            $this->telegram->editMessageText([
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $formattedContent,
                'parse_mode' => 'MarkdownV2',
            ]);

            return;
        } catch (\Exception $e) {
            // Log the markdown conversion failure for debugging
            Log::debug('MarkdownV2 conversion failed, falling back to stripped markdown', [
                'error' => $e->getMessage(),
            ]);
        }

        // Second fallback: strip all markdown and send plain text
        try {
            $strippedContent = $this->stripMarkdown($content);
            $this->editMessage($chatId, $messageId, $strippedContent);

            return;
        } catch (\Exception $e) {
            // Log the stripped markdown failure
            Log::debug('Stripped markdown send failed, falling back to original', [
                'error' => $e->getMessage(),
            ]);
        }

        // Final fallback: send original content as-is
        try {
            $this->editMessage($chatId, $messageId, $content);
        } catch (\Exception $e) {
            Log::error('All message send attempts failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
