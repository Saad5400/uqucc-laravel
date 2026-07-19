<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements OAuthenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, LogsActivity, Notifiable;

    /**
     * Pin spatie's role/permission lookups to the `web` guard.
     *
     * Both the `web` and `api` (Passport) guards resolve to this model, and
     * spatie prefers whichever guard is currently active — so a request
     * authenticated through `auth:api` (the /mcp/admin server) would look for
     * roles and permissions under an `api` guard that was never seeded, and
     * every ability check would silently fail. All roles and permissions are
     * seeded for `web`, so resolve against it regardless of the active guard.
     *
     * @var string
     */
    protected $guard_name = 'web';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'telegram_id',
        'username',
        'url',
        'avatar',
        'requires_review',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'requires_review' => 'boolean',
        ];
    }

    /**
     * Check if user can access the admin management panel
     */
    public function canAccessManagePanel(): bool
    {
        return $this->hasAnyRole(['admin', 'editor']);
    }

    /**
     * Whether this user's content edits must be reviewed before going live.
     * Admins are never gated, even if the flag is set on their account.
     */
    public function mustHaveChangesReviewed(): bool
    {
        return $this->requires_review && ! $this->hasRole('admin');
    }

    /**
     * Whether this user may review (approve/reject) other users' pending
     * changes: a panel user who is not themselves in review mode.
     */
    public function canReviewChanges(): bool
    {
        return $this->canAccessManagePanel() && ! $this->mustHaveChangesReviewed();
    }

    /**
     * Find a user by their Telegram ID
     */
    public static function findByTelegramId(string $telegramId): ?self
    {
        return static::where('telegram_id', $telegramId)->first();
    }

    /**
     * Check if user can manage pages via Telegram bot
     */
    public function canManagePagesViaTelegram(): bool
    {
        return $this->hasAnyRole(['admin', 'editor']) || $this->can('manage-pages');
    }

    /**
     * Get the pages authored by this user
     */
    public function pages(): BelongsToMany
    {
        return $this->belongsToMany(Page::class)->withPivot('order')->withTimestamps();
    }

    /**
     * Configure activity logging options
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'telegram_id', 'username'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
