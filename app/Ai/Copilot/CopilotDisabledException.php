<?php

namespace App\Ai\Copilot;

use RuntimeException;

/**
 * Thrown when a copilot helper is invoked while the admin copilot feature
 * (or the master AI kill switch) is disabled in {@see \App\Settings\AiSettings}.
 * The message is operator-facing Arabic, surfaced as-is in admin-panel
 * notifications.
 */
class CopilotDisabledException extends RuntimeException
{
    public function __construct(string $message = 'مساعد الكتابة الذكي معطل من إعدادات الذكاء الاصطناعي.')
    {
        parent::__construct($message);
    }
}
