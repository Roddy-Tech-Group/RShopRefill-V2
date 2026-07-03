<?php

namespace Tests\Unit;

use App\Domain\Shared\Enums\Currency;
use PHPUnit\Framework\TestCase;

class CurrencyFundingLimitsTest extends TestCase
{
    public function test_minimum_funding_amounts_match_the_configured_floor(): void
    {
        $this->assertSame(1000.00, Currency::NGN->minimumFundingAmount());
        $this->assertSame(2.00, Currency::USD->minimumFundingAmount());
        $this->assertSame(5.00, Currency::GBP->minimumFundingAmount());
        $this->assertSame(20.00, Currency::GHS->minimumFundingAmount());
        $this->assertSame(1500.00, Currency::XAF->minimumFundingAmount());
        $this->assertSame(0.00, Currency::RCOIN->minimumFundingAmount());
    }

    public function test_minimum_never_exceeds_the_maximum_for_any_currency(): void
    {
        foreach (Currency::cases() as $currency) {
            $this->assertLessThanOrEqual(
                $currency->maximumFundingAmount(),
                $currency->minimumFundingAmount(),
                "Minimum funding for {$currency->value} must not exceed its maximum."
            );
        }
    }
}
