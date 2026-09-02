<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TelegramInviteLink extends Model
{
    protected $fillable = [
        'chat_id',
        'chat_title',
        'invite_link',
        'link_name',
        'creator_telegram_user_id',
        'creator_username',
        'creator_name',
        'creator_user_id',
        'member_limit',
        'joins_count',
    ];

    protected function casts(): array
    {
        return [
            'chat_id' => 'integer',
            'creator_telegram_user_id' => 'integer',
            'member_limit' => 'integer',
            'joins_count' => 'integer',
        ];
    }

    /**
     * The panel user behind the Telegram account that requested the link, when known.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_user_id');
    }

    /**
     * @return HasMany<TelegramInviteLinkJoin, $this>
     */
    public function joins(): HasMany
    {
        return $this->hasMany(TelegramInviteLinkJoin::class);
    }
}
