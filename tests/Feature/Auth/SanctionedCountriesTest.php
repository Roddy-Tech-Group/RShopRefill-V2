<?php

namespace Tests\Feature\Auth;

use App\Models\SiteSetting;
use App\Models\User;
use App\Support\PhoneCountries;
use App\Support\SanctionedCountries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Stevebauman\Location\Facades\Location;
use Tests\TestCase;

/**
 * No account is opened in a country under comprehensive sanctions: none of
 * them is offered where an account's country is chosen, and an account that
 * names one is refused, on the web and in the mobile app alike.
 */
class SanctionedCountriesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Location::shouldReceive('get')->andReturn(false);
        SiteSetting::put('features.signup_enabled', 'on', 'features');
    }

    public function test_no_sanctioned_country_is_offered(): void
    {
        $offered = PhoneCountries::isoCodes();

        foreach (SanctionedCountries::CODES as $iso) {
            $this->assertNotContains($iso, $offered, "{$iso} must not be offered as an account's country");
        }

        // The rest of the world still is.
        $this->assertContains('NG', $offered);
        $this->assertContains('RU', $offered, 'sectoral sanctions are a business decision, not this list');
    }

    public function test_the_app_refuses_an_account_in_a_sanctioned_country(): void
    {
        $this->withHeaders(['Accept' => 'application/json', 'X-Installation-ID' => (string) Str::uuid()])
            ->postJson(route('api.mobile.register.check'), [
                'step' => 2,
                'country' => 'IR',
                'phone' => '9123456789',
                'gender' => 'male',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('country');

        $this->assertSame(0, User::count());
    }
}
