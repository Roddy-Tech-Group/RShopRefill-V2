<?php

namespace Tests\Feature\Payment;

use App\Domain\Payment\Providers\FlutterwavePaymentProvider;
use App\Models\PaymentAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The bank-transfer card shows the customer an account name that Flutterwave's
 * API does not supply, so the provider labels it with our legal company name.
 * It must read "Roddy Technologies LTD", matching what the customer's banking
 * app resolves for the virtual account, or transfers get abandoned as
 * suspicious. The name changed with the 2026 Flutterwave compliance review;
 * this pins it so a re-rename never ships half-done.
 */
class BankTransferAccountNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_virtual_account_is_labeled_with_the_legal_company_name(): void
    {
        config(['services.flutterwave.secret_key' => 'FLWSECK_TEST-not-a-mock-key']);

        Http::fake([
            'api.flutterwave.com/v3/charges?type=bank_transfer' => Http::response([
                'status' => 'success',
                'meta' => [
                    'authorization' => [
                        'transfer_bank' => 'Wema Bank',
                        'transfer_account' => '9982123456',
                        'transfer_amount' => 5000,
                    ],
                ],
            ]),
        ]);

        $user = User::factory()->create();
        $attempt = new PaymentAttempt([
            'amount' => 5000,
            'currency' => 'NGN',
            'idempotency_key' => 'test-tx-ref-1',
        ]);
        $attempt->setRelation('user', $user);

        $result = app(FlutterwavePaymentProvider::class)->chargeBankTransfer($attempt);

        $this->assertSame('awaiting_transfer', $result['status']);
        $this->assertSame('Roddy Technologies LTD', $result['bank_details']['account_name']);
        $this->assertSame('9982123456', $result['bank_details']['account_number']);
    }
}
