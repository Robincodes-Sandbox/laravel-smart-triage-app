<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoLockTest extends TestCase
{
    use RefreshDatabase;

    protected function lock(?string $password, bool $enabled = true): void
    {
        config(['demo.lock.enabled' => $enabled, 'demo.lock.password' => $password]);
    }

    public function test_the_demo_is_closed_without_the_password(): void
    {
        $this->lock('opensesame');

        $this->get('/')->assertRedirect(route('demo.unlock'));
        $this->get('/logs')->assertRedirect(route('demo.unlock'));
        $this->post('/triage')->assertRedirect(route('demo.unlock'));
    }

    public function test_the_right_password_opens_it(): void
    {
        $this->lock('opensesame');

        $this->post('/unlock', ['password' => 'opensesame'])->assertRedirect(route('repairs'));

        $this->get('/')->assertOk();
    }

    public function test_the_wrong_password_does_not(): void
    {
        $this->lock('opensesame');

        $this->from('/unlock')
            ->post('/unlock', ['password' => 'nope'])
            ->assertRedirect('/unlock')
            ->assertSessionHasErrors('password');

        $this->get('/')->assertRedirect(route('demo.unlock'));
    }

    public function test_a_missing_password_fails_closed_rather_than_open(): void
    {
        // The case that matters: an env var went missing on the server. The
        // demo must not quietly become public.
        $this->lock(null);

        $this->get('/')->assertRedirect(route('demo.unlock'));
        $this->get('/unlock')->assertStatus(401)->assertSee('No demo password is configured');

        $this->post('/unlock', ['password' => ''])->assertSessionHasErrors('password');
        $this->post('/unlock', ['password' => 'anything'])->assertSessionHasErrors('password');

        $this->get('/')->assertRedirect(route('demo.unlock'));
    }

    public function test_the_lock_can_be_turned_off_for_local_work(): void
    {
        $this->lock(null, enabled: false);

        $this->get('/')->assertOk();
    }

    public function test_guessing_is_rate_limited(): void
    {
        $this->lock('opensesame');

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $this->post('/unlock', ['password' => 'wrong'.$attempt]);
        }

        $this->post('/unlock', ['password' => 'opensesame'])
            ->assertSessionHasErrors('password');

        // Still locked even though the last guess was correct.
        $this->get('/')->assertRedirect(route('demo.unlock'));
    }
}
