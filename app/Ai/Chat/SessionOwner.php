<?php

namespace App\Ai\Chat;

/**
 * The anonymous conversation participant. The public site has no user
 * accounts, so laravel/ai's conversation store (which keys threads by the
 * participant's `id`) is handed this value object instead of a User model:
 * the id is the visitor's session id on the web, or a transport-prefixed
 * key such as "telegram:12345" for the bot. The agent_conversations
 * migration types `user_id` as a string for exactly this reason.
 */
class SessionOwner
{
    public function __construct(public readonly string $id) {}
}
