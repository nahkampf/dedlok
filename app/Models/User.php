<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** Whether this account holds a spent alpha key, once asked (`isTester()`). */
    private ?bool $tester = null;

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    /**
     * Every user has exactly one character; characters.user_id is unique.
     *
     * @return HasOne<Character, $this>
     */
    public function character(): HasOne
    {
        return $this->hasOne(Character::class);
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_supporter' => 'boolean',
            'supporter_since' => 'immutable_datetime',
            'supporter_until' => 'immutable_datetime',
            'seen_discord_at' => 'immutable_datetime',
            'sound_enabled' => 'boolean',
            'last_active_at' => 'immutable_datetime',
        ];
    }

    /**
     * May this account edit the game's content?
     *
     * Deliberately not in #[Fillable]: no request can grant it however the
     * payload is shaped. `php artisan admin:grant` is the only writer.
     */
    /** A supporter of the game. Outside #[Fillable]; `App\Supporters\Supporters` is the only writer. */
    public function isSupporter(): bool
    {
        return (bool) $this->is_supporter && ($this->supporter_until === null || $this->supporter_until->isFuture());
    }

    /** Registered with an alpha key. The spent key is the fact (`beta_keys.user_id`), so there is no column to keep in step. */
    public function isTester(): bool
    {
        // Asked once per badge drawn, and a page draws dozens: remembered for the life of this instance.
        return $this->tester ??= BetaKey::query()->where('user_id', $this->getKey())->exists();
    }

    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }
}
