<?php

namespace Tests\Feature;

use App\Events\SyncRequested;
use App\Models\Message;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RealtimeSignalTest extends TestCase
{
    use RefreshDatabase;

    public function test_sending_a_message_pings_the_receiver(): void
    {
        Event::fake([SyncRequested::class]);
        [$ali, $vali] = User::factory()->count(2)->create();

        $this->actingAs($ali)->postJson("/api/chats/{$vali->id}/messages", ['body' => 'Salom'])->assertCreated();

        Event::assertDispatched(SyncRequested::class, fn (SyncRequested $event) => $event->topic === SyncRequested::TOPIC_MESSAGES
            && in_array($vali->id, $event->userIds, true));
    }

    public function test_posting_a_story_pings_chat_partners(): void
    {
        Event::fake([SyncRequested::class]);
        [$ali, $vali, $stranger] = User::factory()->count(3)->create();
        Message::factory()->create(['sender_id' => $ali->id, 'receiver_id' => $vali->id]);

        $this->actingAs($ali)
            ->post('/api/stories', ['file' => UploadedFile::fake()->image('s.jpg')], ['Accept' => 'application/json'])
            ->assertCreated();

        Event::assertDispatched(SyncRequested::class, fn (SyncRequested $event) => $event->topic === SyncRequested::TOPIC_STORIES
            && in_array($vali->id, $event->userIds, true)
            && ! in_array($stranger->id, $event->userIds, true));
    }

    public function test_first_view_pings_the_story_owner_only_once(): void
    {
        Event::fake([SyncRequested::class]);
        [$ali, $vali] = User::factory()->count(2)->create();
        Message::factory()->create(['sender_id' => $ali->id, 'receiver_id' => $vali->id]);
        $story = Story::factory()->create(['user_id' => $ali->id]);

        $this->actingAs($vali)->postJson("/api/stories/{$story->id}/view")->assertOk();
        $this->actingAs($vali)->postJson("/api/stories/{$story->id}/view")->assertOk();

        Event::assertDispatchedTimes(SyncRequested::class, 1);
        Event::assertDispatched(SyncRequested::class, fn (SyncRequested $event) => $event->topic === SyncRequested::TOPIC_STORY_VIEWED
            && $event->userIds === [$ali->id]
            && $event->data === ['story_id' => $story->id]);
    }

    public function test_a_stopped_websocket_server_does_not_break_requests(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'k',
            'broadcasting.connections.reverb.secret' => 's',
            'broadcasting.connections.reverb.app_id' => '1',
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'broadcasting.connections.reverb.options.port' => 1,
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'broadcasting.connections.reverb.options.useTLS' => false,
        ]);
        [$ali, $vali] = User::factory()->count(2)->create();

        $this->actingAs($ali)->postJson("/api/chats/{$vali->id}/messages", ['body' => 'Salom'])->assertCreated();
    }
}
