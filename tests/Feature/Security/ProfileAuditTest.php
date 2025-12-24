<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_update_creates_audit_log(): void
    {
        $user = User::factory()->create([
            'role' => 'doctor',
            'crm' => '12345/SP',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->patchJson('/api/me', [
                'name' => 'Profissional Atualizado',
                'email' => 'novo-email@example.com',
                'crm' => '54321/SP',
            ])
            ->assertOk()
            ->assertJsonFragment(['name' => 'Profissional Atualizado']);

        $this->assertDatabaseHas('user_audit_logs', [
            'user_id' => $user->id,
        ]);
    }
}
