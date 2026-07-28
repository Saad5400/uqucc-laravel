<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Discord-role-like unit people join and get mentioned by («علوم حاسب»,
 * «العابدية», «1448»), scoped to one Telegram chat. Team names are unique per
 * chat so members join and mention them by name alone. Optionally grouped
 * under a {@see TelegramTeamCategory} for the listing.
 */
class TelegramTeam extends Model
{
    /** @use HasFactory<\Database\Factories\TelegramTeamFactory> */
    use HasFactory;

    protected $fillable = [
        'chat_id',
        'category_id',
        'name',
        'created_by_telegram_id',
    ];

    protected function casts(): array
    {
        return [
            'chat_id' => 'integer',
            'created_by_telegram_id' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TelegramTeamCategory::class, 'category_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(TelegramTeamMember::class, 'team_id');
    }

    public static function findByName(int $chatId, string $name): ?self
    {
        return static::query()->where('chat_id', $chatId)->where('name', $name)->first();
    }
}
