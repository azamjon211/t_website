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
}
