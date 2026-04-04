<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_via_api(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Elmar Hepp',
            'email' => 'elmar@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ]);

        $response->assertCreated()->assertJsonStructure([
            'token',
            'user' => ['id', 'name', 'email'],
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'elmar@example.com',
        ]);
    }

    public function test_user_can_log_in_and_log_out_via_api(): void
    {
        $user = User::factory()->create([
            'email' => 'elmar@example.com',
            'password' => 'secret-password',
        ]);

        $loginResponse = $this->postJson('/api/login', [
            'email' => 'elmar@example.com',
            'password' => 'secret-password',
        ]);

        $loginResponse->assertOk()->assertJsonStructure([
            'token',
            'user' => ['id', 'name', 'email'],
        ]);

        $token = $loginResponse->json('token');

        $this->assertNotNull($token);
        $this->assertSame(1, PersonalAccessToken::query()->count());

        $logoutResponse = $this
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/logout');

        $logoutResponse->assertOk()->assertJson([
            'message' => 'Logged out successfully.',
        ]);

        $this->assertSame(0, PersonalAccessToken::query()->count());
        $this->assertSame($user->id, User::query()->firstOrFail()->id);
    }
}
