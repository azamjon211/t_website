<?php

namespace App\Http\Resources;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if ($this->trashed()) {
            return [
                'id' => $this->id,
                'sender_id' => $this->sender_id,
                'receiver_id' => $this->receiver_id,
                'deleted' => true,
            ];
        }

        return [
            'id' => $this->id,
            'sender_id' => $this->sender_id,
            'receiver_id' => $this->receiver_id,
            'type' => $this->type,
            'body' => $this->body,
            'attachment' => $this->attachment_path === null ? null : [
                'url' => route('media.show', $this->resource),
                'download_url' => route('media.show', ['message' => $this->resource, 'download' => 1]),
                'name' => $this->attachment_name,
                'mime' => $this->attachment_mime,
                'size' => $this->attachment_size,
            ],
            'gif' => $this->type === Message::TYPE_GIF ? $this->payload : null,
            'sticker' => $this->type === Message::TYPE_STICKER ? ($this->payload['code'] ?? null) : null,
            'call' => $this->type === Message::TYPE_CALL ? $this->payload : null,
            'story' => $this->whenLoaded('story', fn () => $this->storyPreview($request), $this->story_id === null ? null : ['expired' => true]),
            'reply_to' => $this->whenLoaded('replyTo', fn () => $this->replyTo === null ? null : [
                'id' => $this->replyTo->id,
                'sender_id' => $this->replyTo->sender_id,
                'type' => $this->replyTo->trashed() ? 'deleted' : $this->replyTo->type,
                'body' => $this->replyTo->trashed() ? null : $this->replyTo->body,
                'attachment_name' => $this->replyTo->trashed() ? null : $this->replyTo->attachment_name,
            ]),
            'read_at' => $this->read_at?->toIso8601String(),
            'edited_at' => $this->edited_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'deleted' => false,
        ];
    }

    /**
     * The replied-to story, with a media link only while the viewer may still open it.
     *
     * @return array{id: int, type: string, url: string|null, caption: string|null, expired: bool}|array{expired: true}|null
     */
    private function storyPreview(Request $request): ?array
    {
        if ($this->story_id === null) {
            return null;
        }

        if ($this->story === null) {
            return ['expired' => true];
        }

        $canView = $request->user()?->can('view', $this->story) ?? false;

        return [
            'id' => $this->story->id,
            'type' => $this->story->type,
            'url' => $canView ? route('stories.media', $this->story) : null,
            'caption' => $this->story->caption,
            'expired' => ! $canView,
        ];
    }
}
