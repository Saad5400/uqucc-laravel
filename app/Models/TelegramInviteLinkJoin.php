<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramInviteLinkJoin extends Model
{
    protected $fillable = [
        'telegram_invite_link_id',
        'chat_id',
        'invite_link',
        'creator_telegram_user_id',
        'joiner_telegram_user_id',
        'joiner_username',
        'joiner_name',
        'source',
        'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'chat_id' => 'integer',
            'creator_telegram_user_id' => 'integer',
            'joiner_telegram_user_id' => 'integer',
            'joined_at' => 'datetime',
        ];
    }

    public function inviteLink(): BelongsTo
    {
        return $this->belongsTo(TelegramInviteLink::class, 'telegram_invite_link_id');
    }
}
