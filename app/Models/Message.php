<?php

namespace App\Models;

use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['sender_id', 'receiver_id', 'body', 'type', 'reply_to_id', 'story_id', 'attachment_path', 'attachment_name', 'attachment_mime', 'attachment_size', 'payload'])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory, SoftDeletes;

    public const TYPE_TEXT = 'text';

    public const TYPE_IMAGE = 'image';

    public const TYPE_VIDEO = 'video';

    public const TYPE_AUDIO = 'audio';

    public const TYPE_FILE = 'file';

    public const TYPE_GIF = 'gif';

    public const TYPE_STICKER = 'sticker';

    /**
     * A finished (or unanswered) call, logged in the chat like Telegram does.
     */
    public const TYPE_CALL = 'call';

    public const CALL_COMPLETED = 'completed';

    public const CALL_MISSED = 'missed';

    public const CALL_DECLINED = 'declined';

    public const CALL_BUSY = 'busy';

    /**
     * Animated Noto emoji codes that can be sent as stickers.
     *
     * @var list<string>
     */
    public const STICKERS = [
        '1f600', '1f603', '1f604', '1f601', '1f606', '1f605', '1f602', '1f923', '1f62d', '1f609',
        '1f60a', '1f607', '1f970', '1f60d', '1f929', '1f618', '1f60b', '1f61c', '1f92a', '1f60e',
        '1f973', '1f914', '1f928', '1f610', '1f644', '1f60f', '1f62c', '1f622', '1f620', '1f621',
        '1f92c', '1f631', '1f633', '1f97a', '1f634', '1f924', '1f922', '1f92e', '1f975', '1f976',
        '1f480', '1f47b', '1f4a9', '1f921', '1f648', '1f649', '1f64a', '2764_fe0f', '1f494', '1f525',
        '2728', '1f389', '1f38a', '1f44d', '1f44e', '1f44f', '1f64f', '1f4aa', '1f44b', '270c_fe0f',
        '1f91d', '1f440', '1f4af', '1f680', '1f308', '2b50', '1f31f', '1f496', '1f381', '1f382',
    ];

    /**
     * Only GIFs hosted on GIPHY's CDN may be embedded.
     */
    public const GIF_URL_PATTERN = '#^https://media[0-9]*\.giphy\.com/[A-Za-z0-9/_.=\-]+$#';

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
            'payload' => 'array',
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
     * The story this message replies to.
     *
     * @return BelongsTo<Story, $this>
     */
    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
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
