<?php

namespace Tests\Unit;

use App\Domain\Payment\Support\GatewayFailure;
use App\Domain\PaymentInsights\Enums\FailureCategory;
use App\Domain\PaymentInsights\Services\FailureReasonClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The failure taxonomy is a contract: gateway verdict in, actionable grade
 * out. These tables ARE the contract; a classifier change that surprises
 * them is a taxonomy change and must be made here first.
 */
class FailureReasonClassifierTest extends TestCase
{
    private FailureReasonClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new FailureReasonClassifier;
    }

    /**
     * @return array<string, array{0: string, 1: FailureCategory, 2: string, 3: bool}>
     */
    public static function issuerVerdicts(): array
    {
        return [
            'insufficient funds' => ['Insufficient Funds', FailureCategory::Customer, 'insufficient_funds', true],
            'not sufficient funds variant' => ['Not sufficient funds', FailureCategory::Customer, 'insufficient_funds', true],
            'do not honor' => ['05 Do Not Honor', FailureCategory::Customer, 'do_not_honor', true],
            'expired card' => ['Expired Card', FailureCategory::Customer, 'expired_card', false],
            'incorrect pin' => ['Incorrect PIN', FailureCategory::Customer, 'incorrect_pin', true],
            'pin tries exceeded' => ['PIN tries exceeded', FailureCategory::Customer, 'incorrect_pin', false],
            'invalid card number' => ['Invalid card number', FailureCategory::Customer, 'invalid_card_details', true],
            'stolen card' => ['Stolen card, pick up', FailureCategory::Customer, 'card_reported_lost', false],
            'fraudulent' => ['Fraudulent transaction', FailureCategory::Customer, 'suspected_fraud', false],
            'restricted' => ['Restricted Card', FailureCategory::Customer, 'card_restricted', false],
            'not permitted' => ['Transaction not permitted to cardholder', FailureCategory::Customer, 'card_restricted', false],
            'limit exceeded' => ['Withdrawal limit exceeded', FailureCategory::Customer, 'limit_exceeded', true],
            '3ds failed' => ['3D Secure authentication failed', FailureCategory::Customer, 'authentication_failed', true],
            'generic decline falls back' => ['Card declined by issuer', FailureCategory::Customer, 'bank_declined', true],
        ];
    }

    #[DataProvider('issuerVerdicts')]
    public function test_issuer_verdicts_grade_to_precise_codes(string $verdict, FailureCategory $category, string $code, bool $retryable): void
    {
        $result = $this->classifier->classify('flutterwave', 'Transaction could not be completed', 'processing', [
            'provider_code' => $verdict,
            'provider_message' => $verdict,
            'http_status' => null,
        ]);

        $this->assertSame($category, $result['category']);
        $this->assertSame($code, $result['code']);
        $this->assertSame($retryable, $result['retryable']);
        $this->assertSame($verdict, $result['provider_code']);
    }

    public function test_gateway_verdict_outranks_the_prose_reason(): void
    {
        // The customer copy says nothing useful; the gateway block knows.
        $result = $this->classifier->classify('flutterwave', 'We could not complete this payment. Please try again.', 'processing', [
            'provider_code' => 'Insufficient Funds',
            'provider_message' => 'Insufficient Funds',
            'http_status' => null,
        ]);

        $this->assertSame('insufficient_funds', $result['code']);
    }

    public function test_nowpayments_statuses_grade_by_exact_code(): void
    {
        $expired = $this->classifier->classify('nowpayments', 'x', 'awaiting_transfer', ['provider_code' => 'expired', 'provider_message' => null, 'http_status' => null]);
        $this->assertSame('crypto_quote_expired', $expired['code']);
        $this->assertTrue($expired['retryable']);

        $underpaid = $this->classifier->classify('nowpayments', 'x', 'awaiting_confirmation', ['provider_code' => 'partially_paid', 'provider_message' => null, 'http_status' => null]);
        $this->assertSame('crypto_underpaid', $underpaid['code']);

        $badKey = $this->classifier->classify('nowpayments', 'x', 'processing', ['provider_code' => 'INVALID_API_KEY', 'provider_message' => 'Invalid api key', 'http_status' => 403]);
        $this->assertSame('gateway_config_error', $badKey['code']);
        $this->assertFalse($badKey['retryable']);
    }

    public function test_nowpayments_codes_do_not_leak_onto_other_providers(): void
    {
        // "expired" from a card provider is not a crypto quote expiring.
        $result = $this->classifier->classify('flutterwave', 'x', 'processing', ['provider_code' => 'expired', 'provider_message' => null, 'http_status' => null]);

        $this->assertNotSame('crypto_quote_expired', $result['code']);
    }

    public function test_internal_and_client_codes_keep_their_verdicts(): void
    {
        $swept = $this->classifier->classify('flutterwave', 'orphaned_timeout', 'processing');
        $this->assertSame('orphaned_timeout', $swept['code']);
        $this->assertSame(FailureCategory::System, $swept['category']);

        $expired = $this->classifier->classify('flutterwave', 'session_expired', 'processing');
        $this->assertSame('session_expired', $expired['code']);
        $this->assertSame(FailureCategory::Customer, $expired['category']);

        $popup = $this->classifier->classify('flutterwave', 'popup_closed', 'processing');
        $this->assertSame('user_cancelled', $popup['code']);
    }

    public function test_http_status_heuristics_are_the_last_resort_before_unknown(): void
    {
        $serverError = $this->classifier->classify('flutterwave', 'zzz', 'processing', ['provider_code' => null, 'provider_message' => null, 'http_status' => 503]);
        $this->assertSame('provider_error', $serverError['code']);
        $this->assertSame(FailureCategory::Provider, $serverError['category']);

        $rateLimited = $this->classifier->classify('flutterwave', 'zzz', 'processing', ['provider_code' => null, 'provider_message' => null, 'http_status' => 429]);
        $this->assertSame('provider_rate_limited', $rateLimited['code']);

        $authError = $this->classifier->classify('flutterwave', 'zzz', 'processing', ['provider_code' => null, 'provider_message' => null, 'http_status' => 401]);
        $this->assertSame('gateway_config_error', $authError['code']);
    }

    public function test_unknown_error_survives_only_when_no_evidence_matches(): void
    {
        $result = $this->classifier->classify('flutterwave', 'Completely novel failure text', 'processing');

        $this->assertSame('unknown_error', $result['code']);
        $this->assertTrue($result['retryable']);
    }

    public function test_a_gateway_message_with_no_rule_is_graded_as_unrecognised_not_unknown(): void
    {
        // The gateway spoke; we simply have no rule for what it said. That is
        // a different situation from capturing nothing, and the dashboard
        // must show it as work to do rather than as a diagnostic gap.
        $result = $this->classifier->classify('flutterwave', 'Transaction could not be completed', 'processing', [
            'provider_code' => null,
            'provider_message' => 'Merchant velocity ceiling reached for BIN range',
            'http_status' => 400,
        ]);

        $this->assertSame('unrecognised_gateway_message', $result['code']);
        $this->assertSame(FailureCategory::System, $result['category']);
        $this->assertTrue($result['retryable'], 'an unrecognised verdict is not yet proof that a retry cannot work');
    }

    public function test_a_gateway_compliance_block_is_graded_as_a_method_we_have_not_been_enabled_for(): void
    {
        // The answer every production Apple Pay attempt received.
        $result = $this->classifier->classify('flutterwave', 'Payment could not be started', 'awaiting_method', [
            'provider_code' => null,
            'provider_message' => 'Transaction failed compliance checks. Please try again later or use a different payment method.',
            'http_status' => 400,
        ]);

        $this->assertSame('gateway_compliance_block', $result['code']);
        $this->assertSame(FailureCategory::System, $result['category']);
        $this->assertFalse($result['retryable']);
    }

    public function test_a_hosted_init_that_answered_with_no_message_is_a_provider_error(): void
    {
        $result = $this->classifier->classify('flutterwave', 'Failed to initialise payment: Unknown error', 'awaiting_method');

        $this->assertSame('provider_error', $result['code']);
        $this->assertSame(FailureCategory::Provider, $result['category']);
        $this->assertTrue($result['retryable']);
    }

    public function test_a_method_the_gateway_does_not_allow_for_the_currency_is_our_configuration_error(): void
    {
        // Verbatim: the answer every Ghana mobile-money attempt received while
        // the provider was charging on the Francophone rail.
        $result = $this->classifier->classify('flutterwave', 'Failed to initialize mobile money: This payment option is not allowed for your currency', 'awaiting_method', [
            'provider_code' => null,
            'provider_message' => 'This payment option is not allowed for your currency',
            'http_status' => 400,
        ]);

        $this->assertSame('gateway_config_error', $result['code']);
        $this->assertSame(FailureCategory::System, $result['category']);
        $this->assertFalse($result['retryable'], 'no customer retry can fix a pairing the rail refuses');
    }

    public function test_every_emittable_code_has_a_human_label(): void
    {
        // A new taxonomy code without a display name would render as raw
        // snake_case on the dashboard; this meta-test makes that a failure.
        $reflection = new \ReflectionClass(FailureReasonClassifier::class);
        $emittable = collect($reflection->getConstant('PROSE_PATTERNS'))->pluck(2)
            ->merge(collect($reflection->getConstant('CLIENT_CODES'))->pluck(1))
            ->merge(collect($reflection->getConstant('INTERNAL_CODES'))->pluck(1))
            ->merge(collect($reflection->getConstant('NOWPAYMENTS_CODES'))->pluck(1))
            ->merge(['frontend_issue', 'provider_rate_limited', 'unrecognised_gateway_message', 'unknown_error'])
            ->unique();

        foreach ($emittable as $code) {
            $this->assertArrayHasKey($code, FailureReasonClassifier::LABELS, "Reason code [{$code}] has no human label");
        }
    }

    public function test_gateway_failure_extracts_flutterwave_shapes(): void
    {
        // Declined charge: HTTP 200, verdict nested in data.processor_response.
        $decline = GatewayFailure::fromFlutterwave([
            'status' => 'success',
            'message' => 'Charge attempted',
            'data' => ['status' => 'failed', 'processor_response' => 'Insufficient Funds'],
        ]);
        $this->assertSame('Insufficient Funds', $decline['provider_code']);
        $this->assertSame('Insufficient Funds', $decline['provider_message']);

        // Gateway-level error: no processor_response, top-level message only.
        $apiError = GatewayFailure::fromFlutterwave(['status' => 'error', 'message' => 'Invalid currency'], 400);
        $this->assertNull($apiError['provider_code']);
        $this->assertSame('Invalid currency', $apiError['provider_message']);
        $this->assertSame(400, $apiError['http_status']);
    }

    public function test_gateway_failure_extracts_nowpayments_shapes(): void
    {
        $apiError = GatewayFailure::fromNowPayments(['code' => 'AMOUNT_MINIMAL_ERROR', 'message' => 'Amount is less than the minimal'], 400);
        $this->assertSame('AMOUNT_MINIMAL_ERROR', $apiError['provider_code']);

        $ipn = GatewayFailure::fromNowPayments(['payment_status' => 'partially_paid']);
        $this->assertSame('partially_paid', $ipn['provider_code']);

        $exception = GatewayFailure::fromException(new \RuntimeException('cURL error 28: Operation timed out'));
        $this->assertNull($exception['provider_code']);
        $this->assertSame('cURL error 28: Operation timed out', $exception['provider_message']);
    }
}
