<?php

namespace Tests\Feature\Fulfillment;

use App\Domain\Fulfillment\Providers\ZenditFulfillmentProvider;
use Tests\TestCase;

/**
 * receipt.accountId is only trusted as the customer-facing code when it agrees
 * with the voucher pin (Apple sends both, equal) or when no pin exists at all
 * (the blank-tile Apple case the accountId capture was built for). MintRoute
 * POSA vouchers (Discord Nitro) send a receipt reference in accountId that
 * contradicts the redeemable voucherId pin - surfacing it as "CODE" above the
 * PIN sent customers to Discord with the wrong string.
 *
 * Payload shapes below are copied from real production orders.
 */
class ZenditAccountIdNormalizationTest extends TestCase
{
    public function test_conflicting_account_id_is_kept_off_the_code_as_a_reference(): void
    {
        // Discord Nitro via MintRoute (production order RSR-20260720-8WKIYY).
        $flat = (new ZenditFulfillmentProvider)->normalizeResponse([
            'status' => 'DONE',
            'receipt' => [
                'voucherId' => '38eq-THbU-ppHJ-NfJS-msPA-sGHQ',
                'accountId' => 'c0a791be85fd55a9b85cd10a2df36b',
                'epin' => '',
                'redemptionUrl' => '',
                'instructions' => "1- Go to https://posa.mintroute.com.\n2- Select Discord service.",
            ],
        ]);

        $this->assertSame('38eq-THbU-ppHJ-NfJS-msPA-sGHQ', $flat['pin']);
        $this->assertArrayNotHasKey('code', $flat);
        $this->assertSame('c0a791be85fd55a9b85cd10a2df36b', $flat['account_reference']);
        $this->assertStringContainsString('posa.mintroute.com', $flat['instructions']);
    }

    public function test_account_id_matching_the_pin_still_surfaces_as_the_code(): void
    {
        // Apple US eGift (production order RSR-20260719-JYKTKU): voucherId and
        // accountId carry the same redemption code. The code slot must survive -
        // the "Add to Apple account" deep link is built from it.
        $flat = (new ZenditFulfillmentProvider)->normalizeResponse([
            'status' => 'DONE',
            'receipt' => ['voucherId' => 'XZGZLNPW8WQL4Q7P', 'accountId' => 'XZGZLNPW8WQL4Q7P'],
        ]);

        $this->assertSame('XZGZLNPW8WQL4Q7P', $flat['code']);
        $this->assertSame('XZGZLNPW8WQL4Q7P', $flat['pin']);
        $this->assertArrayNotHasKey('account_reference', $flat);
    }

    public function test_account_id_without_any_pin_still_becomes_the_code(): void
    {
        // The blank-tile Apple case: epin / voucherId / redemptionUrl all empty,
        // accountId is the only credential Zendit returns.
        $flat = (new ZenditFulfillmentProvider)->normalizeResponse([
            'status' => 'DONE',
            'receipt' => ['voucherId' => '', 'accountId' => 'XQKMWHK6DR9TL7ZM', 'epin' => '', 'redemptionUrl' => ''],
        ]);

        $this->assertSame('XQKMWHK6DR9TL7ZM', $flat['code']);
    }

    public function test_items_code_still_wins_over_a_conflicting_account_id(): void
    {
        // Brands that return a real card number + pin per item are untouched:
        // items[].code overwrites, accountId stays a reference.
        $flat = (new ZenditFulfillmentProvider)->normalizeResponse([
            'status' => 'DONE',
            'items' => [['code' => 'CARD-1111-2222', 'pin' => '9876']],
            'receipt' => ['accountId' => 'REF-0000'],
        ]);

        $this->assertSame('CARD-1111-2222', $flat['code']);
        $this->assertSame('9876', $flat['pin']);
        $this->assertSame('REF-0000', $flat['account_reference']);
    }
}
