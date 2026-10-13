<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_post_an_image_story_that_expires_in_24_hours(): void
    {
        Storage::fake('local');
        $ali = User::factory()->create();

        $this->actingAs($ali)
            ->post('/api/stories', ['file' => UploadedFile::fake()->image('s.jpg'), 'caption' => 'Salom'], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'image')
            ->assertJsonPath('data.caption', 'Salom');

        $story = Story::sole();
        Storage::disk('local')->assertExists($story->media_path);
        $this->assertEqualsWithDelta(now()->addDay()->timestamp, $story->expires_at->timestamp, 5);
    }

    public function test_story_rejects_non_media_files(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/api/stories', ['file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_only_chat_partners_see_stories(): void
    {
        [$ali, $vali, $stranger] = User::factory()->count(3)->create();
        Message::factory()->create(['sender_id' => $ali->id, 'receiver_id' => $vali->id]);
        $story = Story::factory()->create(['user_id' => $ali->id]);

        $this->actingAs($vali)->getJson('/api/stories')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user.id', $ali->id)
            ->assertJsonPath('data.0.has_unseen', true);

        $this->actingAs($stranger)->getJson('/api/stories')->assertJsonCount(0, 'data');
        $this->actingAs($stranger)->get(route('stories.media', $story))->assertForbidden();
        $this->actingAs($stranger)->postJson("/api/stories/{$story->id}/view")->assertForbidden();
    }

    public function test_expired_stories_are_hidden_and_pruned(): void
    {
        Storage::fake('local');
        [$ali, $vali] = User::factory()->count(2)->create();
        Message::factory()->create(['sender_id' => $ali->id, 'receiver_id' => $vali->id]);
        $expired = Story::factory()->expired()->create(['user_id' => $ali->id]);
        Storage::disk('local')->put($expired->media_path, 'x');

        $this->actingAs($vali)->getJson('/api/stories')->assertJsonCount(0, 'data');
        $this->actingAs($vali)->get(route('stories.media', $expired))->assertForbidden();

        $this->artisan('model:prune', ['--model' => [Story::class]])->assertSuccessful();

        $this->assertModelMissing($expired);
        Storage::disk('local')->assertMissing($expired->media_path);
    }

    public function test_views_are_recorded_and_only_author_sees_viewers(): void
    {
        [$ali, $vali] = User::factory()->count(2)->create();
        Message::factory()->create(['sender_id' => $vali->id, 'receiver_id' => $ali->id]);
        $story = Story::factory()->create(['user_id' => $ali->id]);

        $this->actingAs($vali)->postJson("/api/stories/{$story->id}/view")->assertOk();
        $this->actingAs($vali)->postJson("/api/stories/{$story->id}/view")->assertOk();

        $this->actingAs($vali)->getJson('/api/stories')->assertJsonPath('data.0.has_unseen', false);
        $this->actingAs($vali)->getJson("/api/stories/{$story->id}/viewers")->assertForbidden();

        $this->actingAs($ali)->getJson("/api/stories/{$story->id}/viewers")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $vali->id);

        $this->actingAs($ali)->getJson('/api/stories')->assertJsonPath('data.0.stories.0.views_count', 1);
    }

    public function test_only_author_can_delete_story(): void
    {
        Storage::fake('local');
        [$ali, $vali] = User::factory()->count(2)->create();
        Message::factory()->create(['sender_id' => $ali->id, 'receiver_id' => $vali->id]);
        $story = Story::factory()->create(['user_id' => $ali->id]);

        $this->actingAs($vali)->deleteJson("/api/stories/{$story->id}")->assertForbidden();
        $this->actingAs($ali)->deleteJson("/api/stories/{$story->id}")->assertOk();

        $this->assertModelMissing($story);
    }
}
