<?php

namespace Tests\Feature\Communication;

use App\Domain\Communication\Mail\Security\WelcomeMail;
use App\Domain\Communication\Providers\ResendProvider;
use App\Domain\Communication\Services\ResendApiThrottle;
use App\Models\User;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Resend answers 429 when the account exceeds ten requests a second. The
 * provider retries that (and 5xx / connection failures) at the HTTP layer,
 * honouring Retry-After, before it ever surfaces as a job failure.
 */
class ResendProviderRetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.resend.key' => 'test-key']);
    }

    /**
     * @return array<string, mixed>
     */
    private function send(): array
    {
        $user = User::factory()->create();

        $provider = new ResendProvider(new ResendApiThrottle(maxPerSecond: 100, maxWaitMilliseconds: 0));

        return $provider->send(
            to: $user->email,
            from: 'Newsletter <news@rshoprefill.com>',
            replyTo: 'support@rshoprefill.com',
            subject: 'Payments Update',
            mailable: new WelcomeMail($user),
        );
    }

    public function test_a_429_is_retried_and_the_send_succeeds(): void
    {
        Http::fake([
            'api.resend.com/*' => Http::sequence()
                ->push(['message' => 'Too many requests'], 429, ['Retry-After' => '0'])
                ->push(['id' => 'resend-ok'], 200),
        ]);

        $result = $this->send();

        $this->assertSame('resend-ok', $result['id']);
        Http::assertSentCount(2);
    }

    public function test_a_5xx_is_retried_too(): void
    {
        Http::fake([
            'api.resend.com/*' => Http::sequence()
                ->push('upstream hiccup', 503)
                ->push(['id' => 'resend-ok'], 200),
        ]);

        $this->assertSame('resend-ok', $this->send()['id']);
        Http::assertSentCount(2);
    }

    public function test_a_4xx_other_than_429_is_not_retried(): void
    {
        Log::spy();
        Http::fake([
            'api.resend.com/*' => Http::response(['message' => 'Invalid from address'], 422),
        ]);

        $result = $this->send();

        $this->assertSame('Invalid from address', $result['message']);
        Http::assertSentCount(1);
    }

    public function test_persistent_429_gives_up_after_three_attempts_and_reports_failure(): void
    {
        Log::spy();
        Http::fake([
            'api.resend.com/*' => Http::response(['message' => 'Too many requests'], 429, ['Retry-After' => '0']),
        ]);

        $result = $this->send();

        $this->assertSame('Too many requests', $result['message']);
        Http::assertSentCount(3);
    }

    public function test_retry_delay_honours_retry_after_but_never_exceeds_five_seconds(): void
    {
        $provider = new ResendProvider(new ResendApiThrottle(maxPerSecond: 100, maxWaitMilliseconds: 0));
        $delay = fn (int $attempt, \Exception $e): int => $this->retryDelayMilliseconds($attempt, $e);
        $rateLimited = fn (string $retryAfter) => new RequestException(
            new Response(new Psr7Response(429, ['Retry-After' => $retryAfter], '{"message":"Too many requests"}'))
        );

        $this->assertSame(1000, $delay->call($provider, 1, $rateLimited('1')));
        $this->assertSame(5000, $delay->call($provider, 1, $rateLimited('60')));
        // No Retry-After: linear backoff so co-failing workers desynchronise.
        $this->assertSame(500, $delay->call($provider, 1, new ConnectionException('timeout')));
        $this->assertSame(1000, $delay->call($provider, 2, new ConnectionException('timeout')));
    }

    public function test_every_attempt_carries_the_same_idempotency_key_so_a_retry_cannot_double_send(): void
    {
        Http::fake([
            'api.resend.com/*' => Http::sequence()
                ->push('gateway timeout', 504)
                ->push(['id' => 'resend-ok'], 200),
        ]);
        $user = User::factory()->create();
        $provider = new ResendProvider(new ResendApiThrottle(maxPerSecond: 100, maxWaitMilliseconds: 0));

        $provider->send(
            $user->email, 'Security <security@rshoprefill.com>', 'support@rshoprefill.com', 'Your Security Code',
            new WelcomeMail($user), ['idempotency_key' => 'c66a29edf0deb946b1dca999b468880c'],
        );

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->hasHeader('Idempotency-Key', 'email/c66a29edf0deb946b1dca999b468880c'));
        Http::assertNotSent(fn ($request) => ! $request->hasHeader('Idempotency-Key'));
    }

    public function test_no_idempotency_header_when_the_caller_has_no_key(): void
    {
        Http::fake(['api.resend.com/*' => Http::response(['id' => 'resend-ok'], 200)]);

        $this->send();

        Http::assertSent(fn ($request) => ! $request->hasHeader('Idempotency-Key'));
    }

    public function test_each_request_takes_a_throttle_slot(): void
    {
        Http::fake(['api.resend.com/*' => Http::response(['id' => 'resend-ok'], 200)]);
        $user = User::factory()->create();
        $throttle = new ResendApiThrottle(maxPerSecond: 1, maxWaitMilliseconds: 0);
        $provider = new ResendProvider($throttle);
        $sendOnce = fn () => $provider->send($user->email, 'News <news@rshoprefill.com>', 'support@rshoprefill.com', 'Hi', new WelcomeMail($user));

        $sendOnce();

        $this->expectExceptionMessage('Resend API throttle');
        $sendOnce();
    }
}
