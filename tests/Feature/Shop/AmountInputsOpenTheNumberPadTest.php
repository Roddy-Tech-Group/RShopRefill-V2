<?php

namespace Tests\Feature\Shop;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A customer typing an amount on a phone gets the number pad.
 *
 * type="number" alone is not enough: an iPhone opens the full keyboard on its
 * numbers-and-symbols page, which reads as the normal keyboard, and customers
 * reported exactly that on the wallet top-up. inputmode is what selects the
 * pad. Money that can carry decimals gets "decimal"; whole Rcoin gets
 * "numeric".
 */
class AmountInputsOpenTheNumberPadTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function amountInputs(): array
    {
        return [
            'wallet top-up' => ['livewire/dashboard/fund-wallet.blade.php', 'wire:model="amount"', 'decimal'],
            'product custom amount' => ['shop/_amount-fields.blade.php', 'x-model="amount"', 'decimal'],
            'rcoin to wallet' => ['dashboard/rewards.blade.php', 'name="convert_amount"', 'numeric'],
            'rcoin withdrawal' => ['dashboard/rewards.blade.php', 'name="withdraw_amount"', 'numeric'],
        ];
    }

    #[DataProvider('amountInputs')]
    public function test_every_customer_amount_input_asks_for_the_number_pad(string $view, string $marker, string $mode): void
    {
        $source = (string) file_get_contents(resource_path('views/'.$view));

        $this->assertMatchesRegularExpression(
            '/<input(?:(?!>).)*?inputmode="'.$mode.'"(?:(?!>).)*?'.preg_quote($marker, '/').'/s',
            $source,
            "The {$marker} input in {$view} does not open the {$mode} keypad on phones.",
        );
    }
}
