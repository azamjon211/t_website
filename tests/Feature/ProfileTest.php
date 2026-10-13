<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_upload_an_avatar_that_others_can_see(): void
    {
        Storage::fake('local');
        [$ali, $vali] = User::factory()->count(2)->create();

        $response = $this->actingAs($ali)
            ->post('/api/profile/avatar', ['avatar' => UploadedFile::fake()->image('me.png', 800, 600)], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.avatar_url', fn (?string $url) => $url !== null);

        $path = $ali->fresh()->avatar_path;
        Storage::disk('local')->assertExists($path);

        [$width, $height] = getimagesizefromstring(Storage::disk('local')->get($path));
        $this->assertSame([512, 512], [$width, $height]);

        $this->actingAs($vali)->get($response->json('data.avatar_url'))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_replacing_and_removing_avatar_deletes_old_files(): void
    {
        Storage::fake('local');
        $ali = User::factory()->create();

        $this->actingAs($ali)->post('/api/profile/avatar', ['avatar' => UploadedFile::fake()->image('a.png')], ['Accept' => 'application/json']);
        $first = $ali->fresh()->avatar_path;

        $this->actingAs($ali)->post('/api/profile/avatar', ['avatar' => UploadedFile::fake()->image('b.png')], ['Accept' => 'application/json']);
        Storage::disk('local')->assertMissing($first);

        $second = $ali->fresh()->avatar_path;
        $this->actingAs($ali)->deleteJson('/api/profile/avatar')->assertOk()->assertJsonPath('data.avatar_url', null);
        Storage::disk('local')->assertMissing($second);
    }

    public function test_avatar_must_be_an_image(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/api/profile/avatar', ['avatar' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('avatar');
    }

    public function test_user_can_change_display_name(): void
    {
        $ali = User::factory()->create(['name' => 'Old']);

        $this->actingAs($ali)->patchJson('/api/profile', ['name' => 'New Name'])->assertOk()->assertJsonPath('data.name', 'New Name');
    }
}
