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
