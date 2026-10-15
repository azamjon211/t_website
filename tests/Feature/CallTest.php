<?php

namespace Tests\Feature;

use App\Events\SyncRequested;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class CallTest extends TestCase
{
    use RefreshDatabase;

    public function test_invite_is_parked_and_delivered_once_to_the_callee(): void
    {
        Event::fake([SyncRequested::class]);
        [$ali, $vali] = User::factory()->count(2)->create();
        Message::factory()->create(['sender_id' => $ali->id, 'receiver_id' => $vali->id]);
        $callId = (string) Str::uuid();

        $this->actingAs($ali)
            ->postJson("/api/calls/{$vali->id}/signal", ['type' => 'invite', 'call_id' => $callId, 'media' => 'video', 'sdp' => 'v=0 offer'])
            ->assertOk();

        $signalId = null;
        Event::assertDispatched(SyncRequested::class, function (SyncRequested $event) use ($vali, &$signalId) {
            $signalId = $event->data['signal_id'] ?? null;

            return $event->topic === SyncRequested::TOPIC_CALL && $event->userIds === [$vali->id];
        });

        $this->actingAs($ali)->getJson("/api/calls/signals/{$signalId}")->assertNotFound();

        $this->actingAs($vali)
            ->getJson("/api/calls/signals/{$signalId}")
            ->assertOk()
            ->assertJsonPath('data.type', 'invite')
            ->assertJsonPath('data.call_id', $callId)
            ->assertJsonPath('data.media', 'video')
            ->assertJsonPath('data.sdp', 'v=0 offer')
            ->assertJsonPath('data.from', $ali->id);

        $this->actingAs($vali)->getJson("/api/calls/signals/{$signalId}")->assertNotFound();
    }

    public function test_unanswered_call_is_logged_as_missed_in_the_chat(): void
    {
        [$ali, $vali] = $this->partners();
        $callId = (string) Str::uuid();

        $this->signal($ali, $vali, ['type' => 'invite', 'call_id' => $callId, 'media' => 'audio', 'sdp' => 'offer']);
        $this->signal($ali, $vali, ['type' => 'cancel', 'call_id' => $callId]);

        $this->actingAs($vali)
            ->getJson("/api/chats/{$ali->id}/messages")
            ->assertJsonPath('data.1.type', 'call')
            ->assertJsonPath('data.1.sender_id', $ali->id)
            ->assertJsonPath('data.1.call', ['media' => 'audio', 'status' => 'missed', 'duration' => 0])
            ->assertJsonPath('data.1.read_at', null);
    }

    public function test_declined_call_is_logged_once(): void
    {
        [$ali, $vali] = $this->partners();
        $callId = (string) Str::uuid();

        $this->signal($ali, $vali, ['type' => 'invite', 'call_id' => $callId, 'media' => 'video', 'sdp' => 'offer']);
        $this->signal($vali, $ali, ['type' => 'reject', 'call_id' => $callId]);
        $this->signal($ali, $vali, ['type' => 'cancel', 'call_id' => $callId]);

        $calls = Message::query()->where('type', Message::TYPE_CALL)->get();
        $this->assertCount(1, $calls);
        $this->assertSame(['media' => 'video', 'status' => 'declined', 'duration' => 0], $calls->first()->payload);
    }

    public function test_answered_call_is_logged_with_its_duration(): void
    {
        [$ali, $vali] = $this->partners();
        $callId = (string) Str::uuid();

        $this->signal($ali, $vali, ['type' => 'invite', 'call_id' => $callId, 'media' => 'audio', 'sdp' => 'offer']);
        $this->signal($vali, $ali, ['type' => 'accept', 'call_id' => $callId, 'sdp' => 'answer']);
        $this->travel(95)->seconds();
        $this->signal($vali, $ali, ['type' => 'end', 'call_id' => $callId]);

        $call = Message::query()->where('type', Message::TYPE_CALL)->sole();
        $this->assertSame($ali->id, $call->sender_id);
        $this->assertSame('completed', $call->payload['status']);
        $this->assertEqualsWithDelta(95, $call->payload['duration'], 1);
    }

    public function test_outsiders_cannot_end_someone_elses_call(): void
    {
        [$ali, $vali] = $this->partners();
        $mallory = User::factory()->create();
        Message::factory()->create(['sender_id' => $mallory->id, 'receiver_id' => $ali->id]);
        $callId = (string) Str::uuid();

        $this->signal($ali, $vali, ['type' => 'invite', 'call_id' => $callId, 'media' => 'audio', 'sdp' => 'offer']);
        $this->signal($mallory, $ali, ['type' => 'end', 'call_id' => $callId]);

        $this->assertSame(0, Message::query()->where('type', Message::TYPE_CALL)->count());
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function partners(): array
    {
        [$ali, $vali] = User::factory()->count(2)->create();
        Message::factory()->create(['sender_id' => $ali->id, 'receiver_id' => $vali->id, 'read_at' => now()]);

        return [$ali, $vali];
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function signal(User $from, User $to, array $payload): void
    {
        $this->actingAs($from)->postJson("/api/calls/{$to->id}/signal", $payload)->assertOk();
    }

    public function test_sdp_is_relayed_byte_for_byte_including_the_trailing_crlf(): void
    {
        Event::fake([SyncRequested::class]);
        [$ali, $vali] = $this->partners();
        $sdp = "v=0\r\no=- 1 2 IN IP4 127.0.0.1\r\na=ssrc:1 msid:stream track\r\n";

        $this->signal($ali, $vali, ['type' => 'invite', 'call_id' => (string) Str::uuid(), 'media' => 'audio', 'sdp' => $sdp]);

        $signalId = null;
        Event::assertDispatched(SyncRequested::class, function (SyncRequested $event) use (&$signalId) {
            $signalId = $event->data['signal_id'] ?? $signalId;

            return true;
        });

        $this->actingAs($vali)->getJson("/api/calls/signals/{$signalId}")->assertJsonPath('data.sdp', $sdp);
    }

    public function test_cannot_call_a_stranger(): void
    {
        [$ali, $stranger] = User::factory()->count(2)->create();

        $this->actingAs($ali)
            ->postJson("/api/calls/{$stranger->id}/signal", ['type' => 'invite', 'call_id' => (string) Str::uuid(), 'media' => 'audio', 'sdp' => 'x'])
            ->assertForbidden();
    }

    public function test_invite_requires_an_offer(): void
    {
        [$ali, $vali] = User::factory()->count(2)->create();
        Message::factory()->create(['sender_id' => $ali->id, 'receiver_id' => $vali->id]);

        $this->actingAs($ali)
            ->postJson("/api/calls/{$vali->id}/signal", ['type' => 'invite', 'call_id' => (string) Str::uuid(), 'media' => 'audio'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sdp');
    }

    public function test_ice_servers_include_short_lived_turn_credentials_when_configured(): void
    {
        config(['services.turn.url' => 'turn:example.com:3478', 'services.turn.secret' => 'shh']);
        $ali = User::factory()->create();

        $response = $this->actingAs($ali)->getJson('/api/calls/ice-servers')->assertOk();

        $turn = $response->json('data.1');
        $this->assertSame(['turn:example.com:3478'], $turn['urls']);
        $this->assertStringEndsWith(":{$ali->id}", $turn['username']);
        $this->assertSame(base64_encode(hash_hmac('sha1', $turn['username'], 'shh', true)), $turn['credential']);
    }

    public function test_ice_servers_fall_back_to_stun_only(): void
    {
        config(['services.turn.url' => null, 'services.turn.secret' => null]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/calls/ice-servers')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
