<?php

namespace App\Ai\Admin;

use App\Models\User;

/**
 * The admin assistant's conversation participant. laravel/ai keys threads by
 * the participant's `id` (a string column, see the agent_conversations
 * migration), and the public assistant already namespaces non-web transports
 * ("telegram:12345"); admin threads follow the same convention with an
 * "admin:{user id}" key so they can never collide with a visitor session id
 * and are self-describing in the conversations table.
 */
class AdminOwner
{
    public readonly string $id;

    public function __construct(User $user)
    {
        $this->id = 'admin:'.$user->getKey();
    }
}
