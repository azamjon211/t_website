<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatExtrasTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_send_a_sticker(): void
    {
        [$ali, $vali] = User::factory()->count(2)->create();

        $this->actingAs($ali)
            ->postJson("/api/chats/{$vali->id}/messages", ['sticker' => '1f602'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'sticker')
            ->assertJsonPath('data.sticker', '1f602')
            ->assertJsonPath('data.body', null);
    }

    public function test_unknown_stickers_are_rejected(): void
    {
        [$ali, $vali] = User::factory()->count(2)->create();

        $this->actingAs($ali)
            ->postJson("/api/chats/{$vali->id}/messages", ['sticker' => '../../etc'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sticker');
    }

    public function test_user_can_send_a_giphy_gif(): void
    {
        [$ali, $vali] = User::factory()->count(2)->create();
        $gif = ['url' => 'https://media2.giphy.com/media/v1.Y2lk/abc123/200.gif', 'width' => 356, 'height' => 200];

        $this->actingAs($ali)
            ->postJson("/api/chats/{$vali->id}/messages", ['gif' => $gif])
            ->assertCreated()
            ->assertJsonPath('data.type', 'gif')
            ->assertJsonPath('data.gif', $gif);
    }

    public function test_gifs_from_other_hosts_are_rejected(): void
    {
        [$ali, $vali] = User::factory()->count(2)->create();

        $this->actingAs($ali)
            ->postJson("/api/chats/{$vali->id}/messages", ['gif' => ['url' => 'https://evil.example/x.gif', 'width' => 10, 'height' => 10]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('gif.url');
    }

    public function test_story_reply_carries_a_story_preview(): void
    {
        [$ali, $vali] = User::factory()->count(2)->create();
        Message::factory()->create(['sender_id' => $ali->id, 'receiver_id' => $vali->id]);
        $story = Story::factory()->create(['user_id' => $ali->id, 'caption' => 'Dengiz']);

        $this->actingAs($vali)
            ->postJson("/api/chats/{$ali->id}/messages", ['body' => 'Zo\'r!', 'story_id' => $story->id])
            ->assertCreated()
            ->assertJsonPath('data.story.id', $story->id)
            ->assertJsonPath('data.story.caption', 'Dengiz')
            ->assertJsonPath('data.story.expired', false)
            ->assertJsonPath('data.story.url', route('stories.media', $story));

        $this->actingAs($ali)
            ->getJson("/api/chats/{$vali->id}/messages")
            ->assertJsonPath('data.1.story.id', $story->id);
    }

    public function test_cannot_reply_to_a_story_of_someone_else_in_the_chat(): void
    {
        [$ali, $vali, $stranger] = User::factory()->count(3)->create();
        $story = Story::factory()->create(['user_id' => $stranger->id]);

        $this->actingAs($vali)
            ->postJson("/api/chats/{$ali->id}/messages", ['body' => 'Hey', 'story_id' => $story->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('story_id');
    }

    public function test_expired_story_reply_hides_the_media_link(): void
    {
        [$ali, $vali] = User::factory()->count(2)->create();
        $story = Story::factory()->expired()->create(['user_id' => $ali->id]);
        Message::factory()->create(['sender_id' => $vali->id, 'receiver_id' => $ali->id, 'story_id' => $story->id]);

        $this->actingAs($vali)
            ->getJson("/api/chats/{$ali->id}/messages")
            ->assertJsonPath('data.0.story.expired', true)
            ->assertJsonPath('data.0.story.url', null);
    }

    public function test_gif_search_proxies_giphy_and_hides_the_key(): void
    {
        config(['services.giphy.key' => 'secret-key']);
        Http::fake(['api.giphy.com/*' => Http::response(['data' => [[
            'id' => 'abc',
            'images' => [
                'fixed_height' => ['url' => 'https://media1.giphy.com/media/abc/200.gif?cid=x', 'width' => '300', 'height' => '200'],
                'fixed_width_small' => ['url' => 'https://media1.giphy.com/media/abc/100w.gif?cid=x'],
            ],
        ]]])]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/gifs?q=cat')
            ->assertOk()
            ->assertJsonPath('data.0.url', 'https://media1.giphy.com/media/abc/200.gif')
            ->assertJsonPath('data.0.preview', 'https://media1.giphy.com/media/abc/100w.gif')
            ->assertJsonPath('data.0.width', 300);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/search') && $request['q'] === 'cat');
    }

    public function test_gif_search_reports_missing_configuration(): void
    {
        config(['services.giphy.key' => null]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/gifs')
            ->assertStatus(503);
    }
}
