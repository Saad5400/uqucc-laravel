<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Telegram user's membership in a {@see TelegramTeam}, keyed by their raw
 * Telegram user id in the {@see QuizPlayer} style (members are almost never
 * panel users). Every row records the consent that created it: the member's
 * own «انضم» message id and time, and which group admin approved it.
 */
class TelegramTeamMember extends Model
{
    /** @use HasFactory<\Database\Factories\TelegramTeamMemberFactory> */
    use HasFactory;

    protected $fillable = [
        'team_id',
        'telegram_user_id',
        'first_name',
        'username',
        'consent_message_id',
        'consented_at',
        'added_by_telegram_id',
    ];

    protected function casts(): array
    {
        return [
            'telegram_user_id' => 'integer',
            'consent_message_id' => 'integer',
            'consented_at' => 'datetime',
            'added_by_telegram_id' => 'integer',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(TelegramTeam::class, 'team_id');
    }

    public function displayName(): string
    {
        return trim((string) $this->first_name) !== ''
            ? trim((string) $this->first_name)
            : ($this->username !== null ? '@'.$this->username : 'عضو');
    }
}
