<?php

namespace Tests\Feature\Fulfillment;

use App\Domain\Fulfillment\Providers\ZenditFulfillmentProvider;
use Tests\TestCase;

/**
 * Zendit eSIM payloads must come out of normalizeResponse with the SAME flat
 * contract the Airalo normalizer emits (qrcode_url / lpa / iccid), because
 * every eSIM surface - dashboard orders, order page, delivery email - detects
 * an eSIM by those keys. Without them, a Zendit eSIM falls through to the
 * gift-card template and the customer sees the SM-DP+ address as a "CODE".
 */
class ZenditEsimPayloadTest extends TestCase
{
    public function test_esim_confirmation_emits_airalo_compatible_flat_keys(): void
    {
        $payload = (new ZenditFulfillmentProvider)->normalizeResponse([
            'confirmation' => [
                'iccid' => '8944465400000000001',
                'smdpAddress' => 'rsp-3104.idemia.io',
                'activationCode' => 'K2-1ABCDE-3FGHIJ',
            ],
        ]);

        $this->assertSame('8944465400000000001', $payload['iccid']);
        $this->assertSame('rsp-3104.idemia.io', $payload['lpa']);
        $this->assertSame('LPA:1$rsp-3104.idemia.io$K2-1ABCDE-3FGHIJ', $payload['qr_manual_code']);
        $this->assertStringContainsString(
            urlencode('LPA:1$rsp-3104.idemia.io$K2-1ABCDE-3FGHIJ'),
            $payload['qrcode_url']
        );

        // Legacy keys stay for already-stored payloads and the admin views.
        $this->assertSame('rsp-3104.idemia.io', $payload['esim_lpa']);
        $this->assertSame('8944465400000000001', $payload['esim_iccid']);
    }

    public function test_full_lpa_activation_string_is_used_verbatim(): void
    {
        $payload = (new ZenditFulfillmentProvider)->normalizeResponse([
            'confirmation' => [
                'iccid' => '8944465400000000002',
                'smdpAddress' => 'rsp.zendit.io',
                'activationCode' => 'LPA:1$rsp.zendit.io$FULL-STRING',
            ],
        ]);

        $this->assertSame('LPA:1$rsp.zendit.io$FULL-STRING', $payload['qr_manual_code']);
    }

    public function test_gift_card_payload_gets_no_esim_keys(): void
    {
        $payload = (new ZenditFulfillmentProvider)->normalizeResponse([
            'items' => [['code' => 'GC-CODE-123', 'pin' => '4567']],
        ]);

        $this->assertArrayNotHasKey('lpa', $payload);
        $this->assertArrayNotHasKey('qrcode_url', $payload);
        $this->assertSame('GC-CODE-123', $payload['code']);
    }

    /**
     * Apple US eGift (and similar brands) deliver the redemption code in
     * receipt.accountId while epin / voucherId / redemptionUrl stay empty.
     * Without reading accountId the payload comes out codeless and the order
     * shows the "code appears here" placeholder despite being fulfilled.
     */
    public function test_voucher_account_id_is_surfaced_as_the_code(): void
    {
        $payload = (new ZenditFulfillmentProvider)->normalizeResponse([
            'status' => 'DONE',
            'receipt' => [
                'deliveryType' => 'voucher',
                'epin' => '',
                'voucherId' => '',
                'redemptionUrl' => '',
                'accountId' => 'XQKMWHK6DR9TL7ZM',
            ],
        ]);

        $this->assertSame('XQKMWHK6DR9TL7ZM', $payload['code']);
    }

    public function test_real_item_code_takes_precedence_over_account_id(): void
    {
        $payload = (new ZenditFulfillmentProvider)->normalizeResponse([
            'items' => [['code' => 'REAL-VOUCHER-CODE']],
            'receipt' => ['accountId' => 'ACCT-FALLBACK'],
        ]);

        $this->assertSame('REAL-VOUCHER-CODE', $payload['code']);
    }

    public function test_missing_activation_code_skips_qr_but_keeps_identifiers(): void
    {
        $payload = (new ZenditFulfillmentProvider)->normalizeResponse([
            'confirmation' => [
                'iccid' => '8944465400000000003',
                'smdpAddress' => 'rsp-3104.idemia.io',
            ],
        ]);

        $this->assertSame('rsp-3104.idemia.io', $payload['lpa']);
        $this->assertArrayNotHasKey('qrcode_url', $payload);
    }
}
