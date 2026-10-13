<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_can_be_found_by_username(): void
    {
        $me = User::factory()->create();
        $target = User::factory()->create(['username' => 'jasur', 'name' => 'Jasur']);
        User::factory()->create(['username' => 'other', 'name' => 'Other']);

        $this->actingAs($me)
            ->getJson('/api/users/search?q=@jas')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $target->id)
            ->assertJsonPath('data.0.username', 'jasur');
    }

    public function test_search_treats_like_wildcards_literally(): void
    {
        $me = User::factory()->create();
        User::factory()->create(['username' => 'abc']);

        $this->actingAs($me)
            ->getJson('/api/users/search?q=%25')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_user_can_send_a_text_message_and_partner_can_read_it(): void
    {
        [$ali, $vali] = User::factory()->count(2)->create();

        $this->actingAs($ali)
            ->postJson("/api/chats/{$vali->id}/messages", ['body' => 'Salom!'])
            ->assertCreated()
            ->assertJsonPath('data.body', 'Salom!')
            ->assertJsonPath('data.type', 'text');

        $this->actingAs($vali)
            ->getJson("/api/chats/{$ali->id}/messages")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', 'Salom!')
            ->assertJsonPath('has_more', false);
    }

    public function test_empty_message_is_rejected(): void
    {
        [$ali, $vali] = User::factory()->count(2)->create();

        $this->actingAs($ali)
            ->postJson("/api/chats/{$vali->id}/messages", ['body' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body');
    }

    public function test_image_and_video_uploads_are_typed_from_their_contents(): void
    {
        Storage::fake('local');
        [$ali, $vali] = User::factory()->count(2)->create();

        $image = $this->actingAs($ali)
            ->post("/api/chats/{$vali->id}/messages", ['file' => UploadedFile::fake()->image('photo.jpg', 40, 40)], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'image')
            ->assertJsonPath('data.attachment.name', 'photo.jpg');

        Storage::disk('local')->assertExists(Message::findOrFail($image->json('data.id'))->attachment_path);

        $this->actingAs($ali)
            ->post("/api/chats/{$vali->id}/messages", ['file' => UploadedFile::fake()->create('clip.mp4', 100, 'video/mp4')], ['Accept' => 'application/json'])
            ->assertCreated();
    }

    public function test_html_disguised_as_image_is_stored_as_plain_file(): void
    {
        Storage::fake('local');
        [$ali, $vali] = User::factory()->count(2)->create();

        $file = UploadedFile::fake()->createWithContent('evil.png', '<html><script>alert(1)</script></html>');

        $this->actingAs($ali)
            ->post("/api/chats/{$vali->id}/messages", ['file' => $file], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'file');
    }

    public function test_only_participants_can_download_attachments(): void
    {
        Storage::fake('local');
        [$ali, $vali, $stranger] = User::factory()->count(3)->create();

        $response = $this->actingAs($ali)
            ->post("/api/chats/{$vali->id}/messages", ['file' => UploadedFile::fake()->image('photo.png')], ['Accept' => 'application/json']);
        $message = Message::findOrFail($response->json('data.id'));

        $this->actingAs($vali)->get(route('media.show', $message))->assertOk();
        $this->actingAs($stranger)->get(route('media.show', $message))->assertForbidden();
    }

    public function test_strangers_cannot_edit_or_delete_messages(): void
    {
        [$ali, $vali, $stranger] = User::factory()->count(3)->create();
        $message = Message::factory()->create(['sender_id' => $ali->id, 'receiver_id' => $vali->id]);

        $this->actingAs($stranger)->patchJson("/api/messages/{$message->id}", ['body' => 'x'])->assertForbidden();
        $this->actingAs($vali)->patchJson("/api/messages/{$message->id}", ['body' => 'x'])->assertForbidden();
        $this->actingAs($stranger)->deleteJson("/api/messages/{$message->id}")->assertForbidden();

        $this->assertNotSoftDeleted($message);
    }

    public function test_sender_can_edit_and_participant_can_delete(): void
    {
        [$ali, $vali] = User::factory()->count(2)->create();
        $message = Message::factory()->create(['sender_id' => $ali->id, 'receiver_id' => $vali->id, 'body' => 'old']);

        $this->actingAs($ali)
            ->patchJson("/api/messages/{$message->id}", ['body' => 'new'])
            ->assertOk()
            ->assertJsonPath('data.body', 'new')
            ->assertJsonPath('data.edited_at', fn ($value) => $value !== null);

        $this->actingAs($vali)->deleteJson("/api/messages/{$message->id}")->assertOk();

        $this->assertSoftDeleted($message);
    }

    public function test_reply_must_target_a_message_in_the_same_chat(): void
    {
        [$ali, $vali, $stranger] = User::factory()->count(3)->create();
        $foreign = Message::factory()->create(['sender_id' => $stranger->id, 'receiver_id' => $vali->id]);

        $this->actingAs($ali)
            ->postJson("/api/chats/{$vali->id}/messages", ['body' => 'hi', 'reply_to_id' => $foreign->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reply_to_id');
    }

    public function test_chat_list_shows_last_message_and_unread_count(): void
    {
        [$ali, $vali] = User::factory()->count(2)->create();
        Message::factory()->create(['sender_id' => $vali->id, 'receiver_id' => $ali->id, 'body' => 'one']);
        Message::factory()->create(['sender_id' => $vali->id, 'receiver_id' => $ali->id, 'body' => 'two']);

        $this->actingAs($ali)
            ->getJson('/api/chats')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user.id', $vali->id)
            ->assertJsonPath('data.0.last_message.body', 'two')
            ->assertJsonPath('data.0.unread', 2);

        $this->actingAs($ali)->postJson("/api/chats/{$vali->id}/read")->assertJsonPath('updated', 2);

        $this->actingAs($ali)->getJson('/api/chats')->assertJsonPath('data.0.unread', 0);
    }

    public function test_sync_returns_only_my_recent_messages_and_peer_typing(): void
    {
        [$ali, $vali, $stranger] = User::factory()->count(3)->create();
        $mine = Message::factory()->create(['sender_id' => $vali->id, 'receiver_id' => $ali->id]);
        Message::factory()->create(['sender_id' => $stranger->id, 'receiver_id' => $vali->id]);

        $this->actingAs($vali)->postJson("/api/chats/{$ali->id}/typing")->assertOk();

        $this->actingAs($ali)
            ->getJson('/api/sync?since='.urlencode(now()->subMinute()->toIso8601String())."&peer={$vali->id}")
            ->assertOk()
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.id', $mine->id)
            ->assertJsonPath('peer.typing', true)
            ->assertJsonPath('peer.online', true);
    }

    public function test_api_requires_authentication(): void
    {
        $this->getJson('/api/chats')->assertUnauthorized();
        $this->getJson('/api/sync')->assertUnauthorized();
    }
}
