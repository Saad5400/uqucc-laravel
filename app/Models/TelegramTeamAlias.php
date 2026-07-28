<?php

namespace App\Models;

use App\Helpers\ArabicNormalizer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An extra name a {@see TelegramTeam} answers to — «cs» for «علوم الحاسب».
 * Aliases share one namespace with team names inside a chat, so «انضم cs»
 * and «منشن فريق cs» resolve exactly like the canonical name.
 *
 * `normalized_name` is the matching key (see {@see ArabicNormalizer}); it is
 * kept in sync here so every write path — bot, panel, or tinker — stores a
 * consistent value.
 */
class TelegramTeamAlias extends Model
{
    /** @use HasFactory<\Database\Factories\TelegramTeamAliasFactory> */
    use HasFactory;

    protected $fillable = [
        'team_id',
        'chat_id',
        'name',
        'normalized_name',
    ];

    protected function casts(): array
    {
        return [
            'chat_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $alias): void {
            $alias->normalized_name = ArabicNormalizer::normalize((string) $alias->name);
        });
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(TelegramTeam::class, 'team_id');
    }
}
