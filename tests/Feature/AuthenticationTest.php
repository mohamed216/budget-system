<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshFinancialDatabase;

    public function test_login_authenticates_an_existing_user_and_logout_ends_the_session(): void
    {
        $user = User::factory()->create();
        $this->get('/login')->assertOk();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->get('/')->assertRedirect('/login');
    }

    public function test_invalid_credentials_do_not_authenticate(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'incorrect'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => 'missing@example.com', 'password' => 'incorrect'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => 'missing@example.com', 'password' => 'incorrect'])->assertStatus(429);
    }
}
