<?php

namespace App\Services\Telegram;

use Telegram\Bot\Api;

/**
 * Bot API additions that have not reached the installed Telegram SDK yet.
 */
class TelegramApi extends Api
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function editEphemeralMessageText(array $params): bool
    {
        if (isset($params['reply_markup']) && ! is_string($params['reply_markup'])) {
            $params['reply_markup'] = (string) $params['reply_markup'];
        }

        return (bool) $this->post('editEphemeralMessageText', $params)->getResult();
    }
}
