<?php

namespace Tests\Unit;

use App\Domain\Payment\Support\PaymentMethodNormaliser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The method vocabulary contract.
 *
 * Three spellings of the same method exist in the codebase today (checkout tiles,
 * PaymentSession::getAvailableMethods(), and the gateway's own payment_type), so
 * telemetry and — later — the per-method kill switches are only trustworthy if
 * every spelling collapses onto one key.
 *
 * The null cases matter as much as the mapped ones: an unrecognised method must
 * never be guessed into a real one.
 */
class PaymentMethodNormaliserTest extends TestCase
{
    public static function canonicalPassthrough(): array
    {
        return array_map(fn (string $key) => [$key], PaymentMethodNormaliser::CANONICAL);
    }

    #[DataProvider('canonicalPassthrough')]
    public function test_canonical_keys_pass_through_unchanged(string $key): void
    {
        $this->assertSame($key, PaymentMethodNormaliser::normalise($key));
    }

    public static function aliases(): array
    {
        return [
            // Punctuation drift between our own surfaces.
            'underscored bank transfer' => ['bank_transfer', 'bank_transfer'],
            'hyphenated bank transfer' => ['bank-transfer', 'bank_transfer'],
            'collapsed bank transfer' => ['banktransfer', 'bank_transfer'],
            'spaced bank transfer' => ['bank transfer', 'bank_transfer'],

            // The legacy key emitted by PaymentSession::getAvailableMethods().
            'legacy opay key' => ['opay', 'mobile_wallet'],
            'enaira' => ['enaira', 'mobile_wallet'],

            // The gateway reports the funding type, not the method.
            'credit card' => ['credit', 'card'],
            'debit card' => ['debit', 'card'],

            // Mobile money is suffixed per market, with no closed list.
            'plain mobile money' => ['mobilemoney', 'mobile_money'],
            'ghana mobile money' => ['mobilemoneyghana', 'mobile_money'],
            'francophone mobile money' => ['mobilemoneyfranco', 'mobile_money'],
            'uganda mobile money' => ['mobilemoneyuganda', 'mobile_money'],
            'momo shorthand' => ['momo', 'mobile_money'],

            'apple pay' => ['applepay', 'apple_pay'],
            'internet banking' => ['internetbanking', 'pay_with_bank'],
            'scan to pay' => ['qr', 'bank_qr'],

            // Casing and stray whitespace are never meaningful.
            'shouty' => ['BANK_TRANSFER', 'bank_transfer'],
            'padded' => ['  card  ', 'card'],
        ];
    }

    #[DataProvider('aliases')]
    public function test_known_aliases_resolve_to_a_canonical_key(string $input, string $expected): void
    {
        $this->assertSame($expected, PaymentMethodNormaliser::normalise($input));
    }

    public static function unknowns(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'whitespace only' => ['   '],
            // A gateway family that fronts eight methods identifies none of them.
            'gateway name' => ['flutterwave'],
            // Offered by the gateway, not by us.
            'google pay' => ['googlepay'],
            'nonsense' => ['definitely-not-a-method'],
        ];
    }

    #[DataProvider('unknowns')]
    public function test_unidentifiable_input_returns_null_rather_than_a_guess(?string $input): void
    {
        $this->assertNull(PaymentMethodNormaliser::normalise($input));
    }

    public function test_the_gateway_family_for_crypto_resolves_because_it_fronts_one_method(): void
    {
        $this->assertSame('crypto', PaymentMethodNormaliser::normalise('nowpayments'));
    }

    public function test_it_recovers_the_method_from_a_verification_payload(): void
    {
        $this->assertSame('bank_transfer', PaymentMethodNormaliser::fromGatewayPayload([
            'data' => ['payment_type' => 'bank_transfer'],
        ]));

        $this->assertSame('card', PaymentMethodNormaliser::fromGatewayPayload([
            'data' => ['payment_type' => 'card'],
        ]));
    }

    public function test_a_payload_without_a_payment_type_yields_null(): void
    {
        $this->assertNull(PaymentMethodNormaliser::fromGatewayPayload(null));
        $this->assertNull(PaymentMethodNormaliser::fromGatewayPayload([]));
        $this->assertNull(PaymentMethodNormaliser::fromGatewayPayload(['data' => ['amount' => 100]]));
    }
}
