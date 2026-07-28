<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An organizational folder for a group's teams («التخصص», «الفرع», «الدفعة»),
 * scoped to one Telegram chat. Categories exist purely to group the team
 * listing — nobody joins or mentions a category, and deleting one leaves its
 * teams intact as uncategorized.
 */
class TelegramTeamCategory extends Model
{
    /** @use HasFactory<\Database\Factories\TelegramTeamCategoryFactory> */
    use HasFactory;

    protected $fillable = [
        'chat_id',
        'name',
    ];

    protected function casts(): array
    {
        return [
            'chat_id' => 'integer',
        ];
    }

    public function teams(): HasMany
    {
        return $this->hasMany(TelegramTeam::class, 'category_id');
    }

    public static function findByName(int $chatId, string $name): ?self
    {
        return static::query()->where('chat_id', $chatId)->where('name', $name)->first();
    }
}
