<?php

namespace Tests\Feature\Shop;

use App\Models\NewsletterSubscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The footer newsletter sign-up. It renders on every page, which makes it the
 * easiest write endpoint on the site to hammer, so it carries a honeypot and a
 * per-IP limit rather than relying on the email validator alone.
 */
class NewsletterSignupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('newsletter:127.0.0.1');
    }

    public function test_a_real_address_subscribes(): void
    {
        Volt::test('newsletter-signup')
            ->set('email', 'divine@gmail.com')
            ->call('subscribe')
            ->assertSet('done', true);

        $this->assertDatabaseHas('newsletter_subscribers', [
            'email' => 'divine@gmail.com',
            'status' => 'active',
            'source' => 'footer',
        ]);
    }

    public function test_a_filled_honeypot_is_dropped_without_saying_so(): void
    {
        Volt::test('newsletter-signup')
            ->set('website', 'http://spam.example')
            ->set('email', 'bot@gmail.com')
            ->call('subscribe')
            // Same confirmation a person gets: telling a bot which guard caught
            // it is how it learns to get past the guard.
            ->assertSet('done', true);

        $this->assertSame(0, NewsletterSubscriber::count());
    }

    public function test_the_per_ip_limit_stops_a_flood(): void
    {
        for ($i = 0; $i < 6; $i++) {
            RateLimiter::hit('newsletter:127.0.0.1', 60);
        }

        Volt::test('newsletter-signup')
            ->set('email', 'seventh@gmail.com')
            ->call('subscribe')
            ->assertSet('done', true);

        $this->assertSame(0, NewsletterSubscriber::count());
    }

    public function test_the_honeypot_is_hidden_from_people(): void
    {
        $html = Volt::test('newsletter-signup')->html();

        $this->assertStringContainsString('newsletter-website', $html);
        // Off-canvas and out of the tab order, so nobody can fill it by accident.
        $this->assertStringContainsString('aria-hidden="true"', $html);
        $this->assertStringContainsString('tabindex="-1"', $html);
    }

    public function test_the_pitch_can_shrink_on_a_phone(): void
    {
        $markup = file_get_contents(resource_path('views/livewire/newsletter-signup.blade.php'));

        // Measured at 458px inside a 390px viewport while it was shrink-0, which
        // ran the sentence off the screen.
        $this->assertStringContainsString('min-w-0 shrink items-center', $markup);
        $this->assertStringContainsString('sm:shrink-0', $markup);
    }

    public function test_the_api_endpoint_is_throttled(): void
    {
        $markup = file_get_contents(base_path('routes/api.php'));

        $this->assertStringContainsString("'throttle:6,1'", $markup);
    }
}
