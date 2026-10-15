<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A lightweight "something changed for you" ping over the websocket.
 * Clients react by refetching through the regular JSON endpoints, so no per-user data is pushed.
 */
class SyncRequested implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public const TOPIC_MESSAGES = 'messages';

    public const TOPIC_STORIES = 'stories';

    public const TOPIC_STORY_VIEWED = 'story-viewed';

    public const TOPIC_CALL = 'call';

    /**
     * @param  list<int>  $userIds
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public array $userIds,
        public string $topic,
        public array $data = [],
    ) {}

    /**
     * Broadcast the ping, but never let a stopped websocket server break the request.
     *
     * @param  iterable<int>  $userIds
     * @param  array<string, mixed>  $data
     */
    public static function send(iterable $userIds, string $topic, array $data = []): void
    {
        $userIds = collect($userIds)->map(fn ($id) => (int) $id)->unique()->values()->all();

        if ($userIds === []) {
            return;
        }

        // broadcast() sends from PendingBroadcast's destructor, so it must be released inside the rescue.
        rescue(function () use ($userIds, $topic, $data): void {
            broadcast(new self($userIds, $topic, $data));
        }, report: false);
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return array_map(fn (int $id) => new PrivateChannel("App.Models.User.{$id}"), $this->userIds);
    }

    public function broadcastAs(): string
    {
        return 'sync';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['topic' => $this->topic, ...$this->data];
    }
}
