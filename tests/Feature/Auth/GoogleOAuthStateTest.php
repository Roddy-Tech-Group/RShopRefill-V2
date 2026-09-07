<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Mockery;
use Tests\TestCase;

/**
 * The OAuth `state` round-trip is what stops login-CSRF and forced account
 * linking, so the Google driver must NOT run stateless. The flow used to be
 * stateless in every environment; the local-only escape hatch that remains
 * is never honoured in production.
 */
class GoogleOAuthStateTest extends TestCase
{
    use RefreshDatabase;

    private function provider(): GoogleProvider|Mockery\MockInterface
    {
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('redirect')->andReturn(redirect('https://accounts.google.com/o/oauth2/auth'));

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        return $provider;
    }

    public function test_the_driver_keeps_the_state_check_by_default(): void
    {
        $provider = $this->provider();
        $provider->shouldNotReceive('stateless');

        $this->get(route('auth.google.redirect'))->assertRedirect();
    }

    public function test_the_escape_hatch_is_ignored_in_production(): void
    {
        config(['services.google.stateless' => true]);
        $this->app['env'] = 'production';

        $provider = $this->provider();
        $provider->shouldNotReceive('stateless');

        $this->get(route('auth.google.redirect'))->assertRedirect();
    }

    public function test_the_escape_hatch_works_outside_production(): void
    {
        config(['services.google.stateless' => true]);

        $provider = $this->provider();
        $provider->shouldReceive('stateless')->once()->andReturnSelf();

        $this->get(route('auth.google.redirect'))->assertRedirect();
    }
}
