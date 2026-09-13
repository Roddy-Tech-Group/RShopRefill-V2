<?php

namespace Tests\Unit;

use App\Domain\Shared\Support\CharmPrice;
use PHPUnit\Framework\TestCase;

/**
 * Charm rounding is the ONLY place customer prices are adjusted after
 * conversion, and it must be provably up-only: the charged amount can gain
 * fractions of margin from rounding but can never lose a kobo.
 */
class CharmPriceTest extends TestCase
{
    public function test_large_denomination_currencies_round_up_to_ten(): void
    {
        $ngnRate = 1345.62;

        $this->assertSame(1060.0, CharmPrice::roundUp(1056.80, $ngnRate));
        $this->assertSame(1060.0, CharmPrice::roundUp(1051.00, $ngnRate));
        $this->assertSame(10.0, CharmPrice::roundUp(0.76, $ngnRate));
    }

    public function test_amounts_already_on_the_step_stay_put(): void
    {
        $this->assertSame(1020.0, CharmPrice::roundUp(1020.00, 1345.62));
        // Float noise from a rate multiply must not jump a whole step.
        $this->assertSame(1020.0, CharmPrice::roundUp(1020.0000001, 1345.62));
    }

    public function test_mid_denomination_currencies_round_up_to_one(): void
    {
        $this->assertSame(187.0, CharmPrice::roundUp(186.23, 17.5)); // ZAR
        $this->assertSame(12.0, CharmPrice::roundUp(11.30, 11.26)); // GHS
    }

    public function test_small_denomination_currencies_are_left_alone(): void
    {
        $this->assertSame(10.20, CharmPrice::roundUp(10.20, 1.0));  // USD
        $this->assertSame(9.38, CharmPrice::roundUp(9.38, 0.87));   // EUR
    }

    public function test_rounding_is_never_downward(): void
    {
        foreach ([[1056.80, 1345.62], [186.23, 17.5], [9.3812, 0.87], [0.01, 565.0]] as [$amount, $rate]) {
            $this->assertGreaterThanOrEqual(
                $amount,
                CharmPrice::roundUp($amount, $rate),
                "roundUp({$amount}, {$rate}) went below the input",
            );
        }
    }

    public function test_zero_and_negative_amounts_stay_zero(): void
    {
        $this->assertSame(0.0, CharmPrice::roundUp(0.0, 1345.62));
        $this->assertSame(0.0, CharmPrice::roundUp(-5.0, 1345.62));
    }
}
