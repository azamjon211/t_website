<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

#[Fillable(['name', 'username', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * A user counts as online if they were seen within this many seconds.
     */
    public const ONLINE_WINDOW_SECONDS = 30;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function sentMessages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function receivedMessages(): HasMany
    {
        return $this->hasMany(Message::class, 'receiver_id');
    }

    /**
     * @return HasMany<Story, $this>
     */
    public function stories(): HasMany
    {
        return $this->hasMany(Story::class);
    }

    /**
     * IDs of everyone this user has exchanged messages with. They can see each other's stories.
     *
     * @return Collection<int, int>
     */
    public function partnerIds(): Collection
    {
        return Message::query()
            ->involving($this->id)
            ->selectRaw('DISTINCT CASE WHEN sender_id = ? THEN receiver_id ELSE sender_id END AS partner_id', [$this->id])
            ->pluck('partner_id')
            ->map(fn ($id) => (int) $id)
            ->reject(fn (int $id) => $id === $this->id)
            ->values();
    }

    public function isPartnerOf(User $other): bool
    {
        return Message::query()->between($this->id, $other->id)->exists();
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar_path === null
            ? null
            : route('avatars.show', ['user' => $this->id, 'v' => substr(md5($this->avatar_path), 0, 10)]);
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subSeconds(self::ONLINE_WINDOW_SECONDS));
    }

    /**
     * Record activity, throttled so polling doesn't write on every request.
     */
    public function touchLastSeen(): void
    {
        if ($this->last_seen_at === null || $this->last_seen_at->lt(now()->subSeconds(10))) {
            $this->forceFill(['last_seen_at' => now()])->saveQuietly();
        }
    }
}
