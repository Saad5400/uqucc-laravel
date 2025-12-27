<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotCommandStat extends Model
{
    protected $fillable = [
        'user_id',
        'command_name',
        'chat_type',
        'chat_id',
        'count',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'count' => 'integer',
        ];
    }

    /**
     * Get the user who executed the command
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Track a command usage
     */
    public static function track(string $commandName, ?int $userId = null, ?string $chatType = null, ?int $chatId = null): void
    {
        $stat = static::where('command_name', $commandName)
            ->where('user_id', $userId)
            ->where('chat_type', $chatType)
            ->where('chat_id', $chatId)
            ->first();

        if ($stat) {
            $stat->increment('count');
            $stat->update(['last_used_at' => now()]);
        } else {
            static::create([
                'command_name' => $commandName,
                'user_id' => $userId,
                'chat_type' => $chatType,
                'chat_id' => $chatId,
                'count' => 1,
                'last_used_at' => now(),
            ]);
        }
    }
}
