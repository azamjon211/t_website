<?php

namespace App\Policies;

use App\Models\Story;
use App\Models\User;

class StoryPolicy
{
    /**
     * Stories are visible to their author and to people the author has chatted with, until they expire.
     */
    public function view(User $user, Story $story): bool
    {
        if ($story->user_id === $user->id) {
            return true;
        }

        return ! $story->isExpired() && $user->isPartnerOf($story->user);
    }

    /**
     * Only the author sees who viewed a story.
     */
    public function viewViewers(User $user, Story $story): bool
    {
        return $story->user_id === $user->id;
    }

    public function delete(User $user, Story $story): bool
    {
        return $story->user_id === $user->id;
    }
}
