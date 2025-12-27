<?php

namespace App\Services\Telegram\Handlers;

use App\Models\Page;
use App\Models\User;
use App\Services\Telegram\Traits\SearchesPages;
use Telegram\Bot\Objects\Message;

class EditLinkHandler extends BaseHandler
{
    use SearchesPages;

    public function handle(Message $message): void
    {
        $text = $message->getText();
        $content = is_string($text) ? trim($text) : '';

        if (empty($content)) {
            return;
        }

        // Check if it matches "تعديل <query>" pattern (with or without hamza)
        if (! preg_match('/^تعديل\s+(.+)$/u', $content, $matches)) {
            return;
        }

        $query = $matches[1];
        $userId = $message->getFrom()->getId();

        // Check if user is authorized
        $user = $this->getAuthorizedUser($userId);
        if (! $user) {
            $this->replyAndDelete($message, 'عذراً، ليس لديك صلاحية للوصول إلى روابط التعديل. يرجى تسجيل الدخول أولاً باستخدام: تسجيل دخول');

            return;
        }

        // Search for the page
        $page = $this->searchPage($query);

        if (! $page) {
            $this->replyAndDelete($message, 'الصفحة غير موجودة');

            return;
        }

        // Send the edit link
        $this->sendEditLink($message, $page);
    }

    /**
     * Get authorized user with edit content permission.
     */
    protected function getAuthorizedUser(int $telegramId): ?User
    {
        $user = User::findByTelegramId((string) $telegramId);

        if (! $user || ! $user->canManagePagesViaTelegram()) {
            return null;
        }

        return $user;
    }

    /**
     * Send the admin panel edit link for the page.
     */
    protected function sendEditLink(Message $message, Page $page): void
    {
        $editUrl = url('/admin/pages/'.$page->id.'/edit');

        // Build the raw format content
        $rawContent = $this->buildRawContentFormat($page);

        $responseText = "رابط تعديل الصفحة: {$page->title}\n\n{$editUrl}\n\n";
        $responseText .= "━━━━━━━━━━━━━━━\n";
        $responseText .= "المحتوى بالصيغة الأساسية:\n\n";
        $responseText .= $rawContent;

        $this->replyAndDelete($message, $responseText);
    }

    /**
     * Build the raw content format that the bot accepts.
     */
    protected function buildRawContentFormat(Page $page): string
    {
        $parts = [];

        // Add the message content if available
        $message = $page->quick_response_message;
        if (! empty($message)) {
            // Strip HTML tags to get plain text, but preserve structure
            $plainMessage = $this->stripHtmlFormatting($message);
            $parts[] = $plainMessage;
        }

        // Add buttons in (text|url) format
        $buttons = $page->quick_response_buttons ?? [];
        if (! empty($buttons)) {
            $parts[] = ''; // Empty line before buttons

            // Group buttons by size to determine row layout
            $rowLayout = $this->determineRowLayout($buttons);

            // Add row layout if it's not the default (all half buttons)
            if (! $this->isDefaultLayout($rowLayout)) {
                $parts[] = '[صف:'.implode('-', $rowLayout).']';
            }

            // Add buttons
            foreach ($buttons as $button) {
                if (isset($button['text']) && isset($button['url'])) {
                    $parts[] = "({$button['text']}|{$button['url']})";
                }
            }
        }

        return implode("\n", $parts);
    }

    /**
     * Strip HTML formatting but preserve plain text structure.
     */
    protected function stripHtmlFormatting(string $html): string
    {
        // Remove HTML tags but preserve line breaks
        $text = str_replace(['<br>', '<br/>', '<br />'], "\n", $html);
        $text = preg_replace('/<\/p>/i', "\n\n", $text);
        $text = strip_tags($text);

        // Decode HTML entities to restore original characters
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normalize multiple newlines to double newlines max
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * Determine row layout from button sizes.
     */
    protected function determineRowLayout(array $buttons): array
    {
        $layout = [];
        $currentSize = null;
        $currentCount = 0;

        foreach ($buttons as $button) {
            $size = $button['size'] ?? 'half';

            if ($currentSize === null) {
                $currentSize = $size;
                $currentCount = 1;
            } elseif ($currentSize === $size) {
                $currentCount++;
            } else {
                // Size changed, finalize current row
                $layout[] = $this->sizeToButtonsPerRow($currentSize, $currentCount);
                $currentSize = $size;
                $currentCount = 1;
            }
        }

        // Add last row
        if ($currentCount > 0) {
            $layout[] = $this->sizeToButtonsPerRow($currentSize, $currentCount);
        }

        return $layout;
    }

    /**
     * Convert size to buttons per row.
     */
    protected function sizeToButtonsPerRow(string $size, int $count): int
    {
        return match ($size) {
            'full' => 1,
            'half' => min(2, $count),
            'third' => min(3, $count),
            default => min(2, $count),
        };
    }

    /**
     * Check if layout is the default (all 2s - half buttons).
     */
    protected function isDefaultLayout(array $layout): bool
    {
        foreach ($layout as $buttonsInRow) {
            if ($buttonsInRow !== 2) {
                return false;
            }
        }

        return true;
    }
}
