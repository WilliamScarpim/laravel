<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\TwoFactorCodeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_with_valid_credentials_creates_two_factor_challenge(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'password' => Hash::make('secret123'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'challengeId',
                'expiresAt',
                'user' => ['email', 'role', 'name'],
            ]);

        $this->assertDatabaseHas('two_factor_challenges', [
            'user_id' => $user->id,
            'type' => 'login',
        ]);

        Notification::assertSentTo($user, TwoFactorCodeNotification::class);
    }

    public function test_login_with_invalid_credentials_rejects_requests(): void
    {
        $user = User::factory()->create([
            'email' => 'doctor@example.com',
            'password' => Hash::make('correct-password'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
        $this->assertStringContainsString('inválidos', $response->json('errors.email.0'));

        $this->assertDatabaseMissing('two_factor_challenges', [
            'user_id' => $user->id,
            'type' => 'login',
        ]);
    }
}
