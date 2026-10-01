<?php

namespace Tests\Unit;

use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\Services\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_it_formats_known_currencies_with_their_symbol(): void
    {
        $this->assertSame('₦25,000.00', Money::format(25000, 'NGN'));
        $this->assertSame('$25.00', Money::format(25, 'USD'));
        $this->assertSame('£12.50', Money::format(12.5, 'GBP'));
        $this->assertSame('₵100.00', Money::format(100, 'GHS'));
        $this->assertSame('XAF 3,000.00', Money::format(3000, 'XAF'));
    }

    public function test_it_handles_lowercase_currency_codes(): void
    {
        $this->assertSame('₦100.00', Money::format(100, 'ngn'));
    }

    public function test_it_falls_back_for_unknown_currency_codes(): void
    {
        $this->assertSame('XYZ 50.00', Money::format(50, 'XYZ'));
    }

    public function test_it_renders_amount_only_when_currency_is_null(): void
    {
        $this->assertSame('50.00', Money::format(50, null));
    }

    public function test_code_amount_uses_iso_prefix(): void
    {
        $this->assertSame('NGN 25,000.00', Money::codeAmount(25000, 'NGN'));
        $this->assertSame('USD 25.00', Money::codeAmount(25, 'USD'));
    }

    public function test_symbol_lookup(): void
    {
        $this->assertSame('₦', Money::symbol('NGN'));
        $this->assertSame('$', Money::symbol('USD'));
        $this->assertSame('XYZ', Money::symbol('XYZ'));
    }

    public function test_exact_keeps_two_decimals_and_widens_only_for_sub_cent_amounts(): void
    {
        $this->assertSame('10.51', Money::exact(10.51, 'USD'));
        $this->assertSame('125.50', Money::exact(125.5, 'USD'));
        $this->assertSame('7.00', Money::exact(7, Currency::GBP));
        $this->assertSame('1,234.56', Money::exact(1234.56, 'NGN'));

        // A leftover a 2-decimal render would show as 0.01, the one amount
        // the debit form then refuses as more than the balance.
        $this->assertSame('0.0099', Money::exact(0.0099, 'USD'));
        $this->assertSame('0.009', Money::exact(0.009, 'USD'));
        $this->assertSame('10.5137', Money::exact(10.5137, 'USD'));

        // Never beyond the ledger's four decimals.
        $this->assertSame('0.0001', Money::exact(0.00012, 'USD'));
    }

    public function test_exact_renders_rcoin_as_whole_coins(): void
    {
        $this->assertSame('1,500', Money::exact(1500, 'RCOIN'));
    }

    public function test_it_accepts_a_currency_enum_directly(): void
    {
        // Some callers pass the enum (e.g. wallet models cast `currency` to
        // Currency::class). The helper must not blow up when that happens.
        $this->assertSame('₦25,000.00', Money::format(25000, Currency::NGN));
        $this->assertSame('NGN 25,000.00', Money::codeAmount(25000, Currency::NGN));
        $this->assertSame('₦', Money::symbol(Currency::NGN));
    }
}
