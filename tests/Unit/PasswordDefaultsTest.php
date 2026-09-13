<?php

namespace Tests\Unit;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

/**
 * One password policy for every form (register, reset, change, admin). In
 * production a breached-password check runs against Have I Been Pwned's
 * k-anonymity API; outside production the policy is length alone so tests
 * and local development never touch the network.
 */
class PasswordDefaultsTest extends TestCase
{
    private function passes(string $password): bool
    {
        return Validator::make(['password' => $password], ['password' => Password::defaults()])->passes();
    }

    public function test_outside_production_the_policy_is_length_only(): void
    {
        $this->assertFalse($this->passes('short'));
        $this->assertTrue($this->passes('password'));
    }

    public function test_in_production_a_breached_password_is_refused_without_the_password_leaving_the_server(): void
    {
        $this->app['env'] = 'production';

        $hash = strtoupper(sha1('password'));
        $prefix = substr($hash, 0, 5);
        $suffix = substr($hash, 5);

        Http::fake([
            'api.pwnedpasswords.com/range/*' => Http::response("ABCDEF1234567890ABCDEF1234567890ABCDE:2\r\n{$suffix}:1234\r\n"),
        ]);

        $this->assertFalse($this->passes('password'));
        $this->assertTrue($this->passes('correct horse battery staple'));

        Http::assertSent(fn ($request) => str_ends_with($request->url(), "/range/{$prefix}"));
    }

    public function test_in_production_an_unreachable_breach_api_fails_open(): void
    {
        $this->app['env'] = 'production';

        Http::fake(fn () => throw new ConnectionException('offline'));

        $this->assertTrue($this->passes('password'));
    }
}
