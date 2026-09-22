<?php

namespace Tests\Unit;

use App\Domain\PaymentInsights\Support\IssuerCountry;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The gateway's card country becomes an ISO alpha-2 code or nothing. The
 * table below is the contract; the cases that matter most are the ones the
 * old `substr($raw, 0, 2)` got wrong, where a full name was filed under its
 * first two letters and IRELAND read as Iran.
 */
class IssuerCountryTest extends TestCase
{
    /**
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public static function countries(): array
    {
        return [
            // Codes the gateway already gives us pass straight through.
            'assigned code' => ['US', 'US'],
            'assigned code lowercased' => ['ng', 'NG'],
            'assigned code padded' => ['  GB ', 'GB'],

            // Full names resolve through the country table, whatever the case.
            'full name uppercase' => ['IRELAND', 'IE'],
            'full name titlecase' => ['Sweden', 'SE'],
            'full name lowercase' => ['switzerland', 'CH'],
            'multi-word name' => ['EL SALVADOR', 'SV'],
            'multi-word name with extra spaces' => ['south   africa', 'ZA'],
            'name the table spells differently' => ['UNITED STATES OF AMERICA', 'US'],
            'common abbreviation' => ['UK', 'GB'],

            // The old truncations, now impossible: none of these are assigned.
            'truncated sweden' => ['SW', null],
            'truncated unknown' => ['UN', null],
            'truncated el salvador' => ['EL', null],

            // Explicit "do not know" from the gateway.
            'unknown marker' => ['UNKNOWN', null],
            'not applicable marker' => ['N/A', null],
            'dash marker' => ['-', null],

            // Nothing usable.
            'null' => [null, null],
            'empty' => ['', null],
            'whitespace' => ['   ', null],
            'gibberish' => ['Planet Earth', null],
            'digits' => ['840', null],
        ];
    }

    #[DataProvider('countries')]
    public function test_it_resolves_to_an_assigned_alpha_2_code_or_null(?string $raw, ?string $expected): void
    {
        $this->assertSame($expected, IssuerCountry::normalise($raw));
    }

    /**
     * A name we cannot resolve is logged with the gateway's own words, so the
     * alias table can grow from evidence rather than guesses.
     */
    public function test_an_unrecognised_value_is_logged_and_never_guessed(): void
    {
        Log::shouldReceive('info')
            ->once()
            ->withArgs(fn (string $event, array $context) => $event === 'payment.issuer_country_unrecognised' && $context['raw'] === 'Ruritania');

        $this->assertNull(IssuerCountry::normalise('Ruritania'));
    }

    /**
     * Two letters that happen to be an assigned code are trusted only because
     * they ARE a code. IRELAND must never become IR on the way through.
     */
    public function test_ireland_is_ie_not_iran(): void
    {
        $this->assertSame('IE', IssuerCountry::normalise('IRELAND'));
        $this->assertSame('IR', IssuerCountry::normalise('IR'), 'a real IR code from the gateway is still Iran');
        $this->assertSame('IR', IssuerCountry::normalise('Iran'));
    }
}
