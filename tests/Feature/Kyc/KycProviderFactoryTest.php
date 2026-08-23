<?php

namespace Tests\Feature\Kyc;

use App\Domain\Kyc\Providers\ManualKycProvider;
use App\Domain\Kyc\Providers\SmileKycProvider;
use App\Domain\Kyc\Services\KycProviderFactory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * KYC provider resolution. Smile ID serves every market today (the African vs
 * global product split lives inside SmileKycProvider), so all users route to
 * Smile; unknown keys fall back to the manual reviewer.
 */
class KycProviderFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_user_routes_to_smile(): void
    {
        $factory = app(KycProviderFactory::class);

        foreach (['NG', 'US', 'GB', null] as $country) {
            $user = User::factory()->create(['country' => $country]);
            $this->assertInstanceOf(SmileKycProvider::class, $factory->forUser($user));
        }
    }

    public function test_make_resolves_keys_and_falls_back_to_manual(): void
    {
        $factory = app(KycProviderFactory::class);

        $this->assertInstanceOf(SmileKycProvider::class, $factory->make('smile'));
        $this->assertInstanceOf(ManualKycProvider::class, $factory->make('manual'));
        $this->assertInstanceOf(ManualKycProvider::class, $factory->make('unknown-vendor'));
    }
}
