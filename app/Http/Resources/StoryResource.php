<?php

namespace App\Http\Resources;

use App\Models\Story;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Story
 */
class StoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isMine = $this->user_id === $request->user()->id;

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'type' => $this->type,
            'url' => route('stories.media', $this->resource),
            'caption' => $this->caption,
            'seen' => $isMine || (bool) $this->seen_by_me,
            'views_count' => $this->when($isMine, fn () => (int) $this->viewers_count),
            'created_at' => $this->created_at->toIso8601String(),
            'expires_at' => $this->expires_at->toIso8601String(),
        ];
    }
}
