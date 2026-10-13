<?php

namespace App\Policies;

use App\Models\Message;
use App\Models\User;

class MessagePolicy
{
    /**
     * Only the two people in the conversation may see a message or its attachment.
     */
    public function view(User $user, Message $message): bool
    {
        return $message->isParticipant($user);
    }

    /**
     * Only the author may edit, and only text content.
     */
    public function update(User $user, Message $message): bool
    {
        return $message->sender_id === $user->id;
    }

    /**
     * Either participant may delete a message for everyone (like Telegram).
     */
    public function delete(User $user, Message $message): bool
    {
        return $message->isParticipant($user);
    }
}
