<?php

namespace App\Models;

use App\Helpers\ArabicNormalizer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Discord-role-like unit people join and get mentioned by («علوم الحاسب»,
 * «العابدية», «1448»), scoped to one Telegram chat. Optionally grouped under a
 * {@see TelegramTeamCategory} for the listing.
 *
 * A team answers to its own name and to any of its {@see TelegramTeamAlias}
 * rows. Matching is normalization-insensitive (see {@see ArabicNormalizer}):
 * hamza forms, ta marbuta, letter case and Arabic-Indic digits all fold, so
 * «٤٥» finds «45» and «الامن السيبراني» finds «الأمن السيبراني» with no alias
 * needed. Team names and aliases share one namespace per chat — a name is
 * taken if either kind of row already normalizes to it.
 */
class TelegramTeam extends Model
{
    /** @use HasFactory<\Database\Factories\TelegramTeamFactory> */
    use HasFactory;

    protected $fillable = [
        'chat_id',
        'category_id',
        'name',
        'normalized_name',
        'created_by_telegram_id',
    ];

    protected function casts(): array
    {
        return [
            'chat_id' => 'integer',
            'created_by_telegram_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $team): void {
            $team->normalized_name = ArabicNormalizer::normalize((string) $team->name);
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TelegramTeamCategory::class, 'category_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(TelegramTeamMember::class, 'team_id');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(TelegramTeamAlias::class, 'team_id');
    }

    /**
     * Find a chat's team by its own name or any of its aliases, ignoring
     * spelling variants the normalizer folds.
     */
    public static function findByName(int $chatId, string $name): ?self
    {
        $normalized = ArabicNormalizer::normalize($name);

        if ($normalized === '') {
            return null;
        }

        return static::query()
            ->where('chat_id', $chatId)
            ->where(fn ($query) => $query
                ->where('normalized_name', $normalized)
                ->orWhereHas('aliases', fn ($aliases) => $aliases->where('normalized_name', $normalized))
            )
            ->first();
    }

    /**
     * Whether another team or alias in this chat already claims the name.
     * Pass $ignoreTeamId when renaming so a team never collides with itself.
     */
    public static function nameIsTaken(int $chatId, string $name, ?int $ignoreTeamId = null): bool
    {
        $normalized = ArabicNormalizer::normalize($name);

        if ($normalized === '') {
            return false;
        }

        $teamExists = static::query()
            ->where('chat_id', $chatId)
            ->where('normalized_name', $normalized)
            ->when($ignoreTeamId !== null, fn ($query) => $query->whereKeyNot($ignoreTeamId))
            ->exists();

        if ($teamExists) {
            return true;
        }

        return TelegramTeamAlias::query()
            ->where('chat_id', $chatId)
            ->where('normalized_name', $normalized)
            ->when($ignoreTeamId !== null, fn ($query) => $query->where('team_id', '!=', $ignoreTeamId))
            ->exists();
    }
}
