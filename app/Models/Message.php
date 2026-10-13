<?php

namespace App\Models;

use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['sender_id', 'receiver_id', 'body', 'type', 'reply_to_id', 'attachment_path', 'attachment_name', 'attachment_mime', 'attachment_size'])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory, SoftDeletes;

    public const TYPE_TEXT = 'text';

    public const TYPE_IMAGE = 'image';

    public const TYPE_VIDEO = 'video';

    public const TYPE_AUDIO = 'audio';

    public const TYPE_FILE = 'file';

    /**
     * Server-detected MIME types that may be rendered inline in the browser.
     *
     * @var array<string, list<string>>
     */
    public const INLINE_MIME_TYPES = [
        self::TYPE_IMAGE => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/bmp'],
        self::TYPE_VIDEO => ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime'],
        self::TYPE_AUDIO => ['audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'audio/webm', 'audio/mp4', 'audio/aac', 'audio/flac'],
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'edited_at' => 'datetime',
            'attachment_size' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'reply_to_id')->withTrashed();
    }

    /**
     * Messages exchanged between two users, in either direction.
     *
     * @param  Builder<Message>  $query
     */
    public function scopeBetween(Builder $query, int $userId, int $otherUserId): void
    {
        $query->where(function (Builder $query) use ($userId, $otherUserId) {
            $query->where(fn (Builder $query) => $query->where('sender_id', $userId)->where('receiver_id', $otherUserId))
                ->orWhere(fn (Builder $query) => $query->where('sender_id', $otherUserId)->where('receiver_id', $userId));
        });
    }

    /**
     * Messages the given user sent or received.
     *
     * @param  Builder<Message>  $query
     */
    public function scopeInvolving(Builder $query, int $userId): void
    {
        $query->where(fn (Builder $query) => $query->where('sender_id', $userId)->orWhere('receiver_id', $userId));
    }

    public function isParticipant(User $user): bool
    {
        return $this->sender_id === $user->id || $this->receiver_id === $user->id;
    }

    public function partnerIdFor(User $user): int
    {
        return $this->sender_id === $user->id ? $this->receiver_id : $this->sender_id;
    }

    /**
     * Map a server-detected MIME type to a message type.
     */
    public static function typeForMime(?string $mime): string
    {
        foreach (self::INLINE_MIME_TYPES as $type => $mimes) {
            if (in_array($mime, $mimes, true)) {
                return $type;
            }
        }

        return self::TYPE_FILE;
    }
}
