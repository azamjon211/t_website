<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_with_a_unique_username(): void
    {
        $response = $this->post('/register', [
            'name' => 'Ali Valiyev',
            'username' => '@Ali_99',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response->assertRedirect(route('chat'));
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['username' => 'ali_99', 'name' => 'Ali Valiyev']);
    }

    public function test_username_must_be_unique_case_insensitively(): void
    {
        User::factory()->create(['username' => 'ali']);

        $response = $this->post('/register', [
            'name' => 'Another Ali',
            'username' => 'ALI',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_username_rejects_invalid_characters(): void
    {
        $this->post('/register', [
            'name' => 'Bad',
            'username' => '1 bad-name',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertSessionHasErrors('username');
    }

    public function test_user_can_log_in_with_username(): void
    {
        User::factory()->create(['username' => 'vali', 'password' => 'secret123']);

        $this->post('/login', ['username' => '@Vali', 'password' => 'secret123'])
            ->assertRedirect(route('chat'));

        $this->assertAuthenticated();
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create(['username' => 'vali', 'password' => 'secret123']);

        $this->post('/login', ['username' => 'vali', 'password' => 'wrong'])
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_authenticated_user_sees_the_chat_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertOk()
            ->assertSee('id="app"', false);
    }
}
