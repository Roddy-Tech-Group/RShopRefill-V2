<?php

namespace App\Domain\PaymentInsights\Services;

use App\Domain\PaymentInsights\Enums\FailureCategory;
use App\Domain\PaymentInsights\Enums\FailureStage;

/**
 * Grades a payment failure into an actionable verdict.
 *
 * Priority order mirrors trustworthiness of the evidence:
 *   1. The gateway's own verdict (GatewayFailure block: provider_code /
 *      provider_message) matched against known decline codes.
 *   2. Enumerated client telemetry codes from the browser tracker.
 *   3. Keyword heuristics over whatever prose reason we were handed.
 *   4. HTTP-status heuristics when only a transport failure is known.
 *   5. `unknown_error`, which after this pipeline means genuinely unknown.
 *
 * Every verdict carries `retryable`: whether asking this customer to try
 * again could ever work. Insufficient funds: yes, payday exists. Stolen
 * card: no, and the recovery mailer must never invite them back.
 */
class FailureReasonClassifier
{
    /**
     * Browser telemetry codes (resources/js/payment-telemetry.js). Exact
     * matches, checked before prose heuristics so each keeps its own code.
     *
     * @var array<string, array{0: FailureCategory, 1: string, 2: bool}>
     */
    private const CLIENT_CODES = [
        'popup_closed' => [FailureCategory::Customer, 'user_cancelled', true],
        'page_abandoned' => [FailureCategory::Customer, 'user_cancelled', true],
        'popup_blocked' => [FailureCategory::Frontend, 'popup_blocked', true],
        'sdk_load_failed' => [FailureCategory::Frontend, 'sdk_load_failed', true],
        'ad_blocker' => [FailureCategory::Frontend, 'ad_blocker', true],
        'js_exception' => [FailureCategory::Frontend, 'js_exception', true],
        'network_offline' => [FailureCategory::Frontend, 'network_offline', true],
        'network_timeout' => [FailureCategory::Frontend, 'network_timeout', true],
        'slow_network' => [FailureCategory::Frontend, 'slow_network', true],
    ];

    /**
     * Internal platform verdicts (GatewayFailure::internal + sweeper codes).
     * Exact matches on the code.
     *
     * @var array<string, array{0: FailureCategory, 1: string, 2: bool}>
     */
    private const INTERNAL_CODES = [
        'orphaned_timeout' => [FailureCategory::System, 'orphaned_timeout', true],
        'session_expired' => [FailureCategory::Customer, 'session_expired', true],
        'invalid_otp' => [FailureCategory::Customer, 'authentication_failed', true],
        'no_outstanding_balance' => [FailureCategory::System, 'no_outstanding_balance', false],
        'missing_purchase_id' => [FailureCategory::System, 'missing_purchase_id', false],
        'missing_virtual_account' => [FailureCategory::Provider, 'provider_error', true],
        'missing_checkout_link' => [FailureCategory::Provider, 'provider_error', true],
    ];

    /**
     * NOWPayments statuses / API error codes. Exact matches.
     *
     * @var array<string, array{0: FailureCategory, 1: string, 2: bool}>
     */
    private const NOWPAYMENTS_CODES = [
        'expired' => [FailureCategory::Customer, 'crypto_quote_expired', true],
        'partially_paid' => [FailureCategory::Customer, 'crypto_underpaid', true],
        'refunded' => [FailureCategory::Provider, 'refunded_by_gateway', false],
        'amount_minimal_error' => [FailureCategory::Customer, 'below_minimum_amount', true],
        'invalid_api_key' => [FailureCategory::System, 'gateway_config_error', false],
        'bad_create_payment_request' => [FailureCategory::System, 'gateway_config_error', false],
    ];

    /**
     * Prose patterns over the gateway verdict or raw reason, most specific
     * first. Substring matches on the normalised text. Order matters: an
     * "expired card" must never fall into crypto "expired", and specific
     * decline reasons must win over the generic "declined".
     *
     * @var array<int, array{0: string, 1: FailureCategory, 2: string, 3: bool}>
     */
    private const PROSE_PATTERNS = [
        // Issuer verdicts (Flutterwave processor_response / ISO 8583 prose)
        ['insufficient fund', FailureCategory::Customer, 'insufficient_funds', true],
        ['not sufficient fund', FailureCategory::Customer, 'insufficient_funds', true],
        ['do not honor', FailureCategory::Customer, 'do_not_honor', true],
        ['do not honour', FailureCategory::Customer, 'do_not_honor', true],
        ['expired card', FailureCategory::Customer, 'expired_card', false],
        ['card expired', FailureCategory::Customer, 'expired_card', false],
        ['incorrect pin', FailureCategory::Customer, 'incorrect_pin', true],
        ['invalid pin', FailureCategory::Customer, 'incorrect_pin', true],
        ['pin tries exceeded', FailureCategory::Customer, 'incorrect_pin', false],
        ['invalid otp', FailureCategory::Customer, 'authentication_failed', true],
        ['incorrect otp', FailureCategory::Customer, 'authentication_failed', true],
        ['otp validation failed', FailureCategory::Customer, 'authentication_failed', true],
        // "Sorry, your card is not enrolled for 3-D Secure Payments". The bank
        // has not switched online verification on for the card, so the same
        // card can never pass; another card, or the bank, can. Ahead of the
        // generic 3-D Secure rule, which reads it as a failed verification.
        // Tied to the 3-D Secure wording on purpose: a bare "not enrolled"
        // would also catch a mobile money "customer not enrolled" and tell
        // that customer to fix a card they never used.
        ['enrolled for 3-d secure', FailureCategory::Customer, 'card_not_enrolled', false],
        ['enrolled for 3d secure', FailureCategory::Customer, 'card_not_enrolled', false],
        ['3d secure', FailureCategory::Customer, 'authentication_failed', true],
        // The gateway's own spelling carries a hyphen, which "3d secure" never
        // matched.
        ['3-d secure', FailureCategory::Customer, 'authentication_failed', true],
        ['authentication failed', FailureCategory::Customer, 'authentication_failed', true],
        ['invalid card', FailureCategory::Customer, 'invalid_card_details', true],
        ['incorrect card', FailureCategory::Customer, 'invalid_card_details', true],
        ['invalid cvv', FailureCategory::Customer, 'invalid_card_details', true],
        ['card number', FailureCategory::Customer, 'invalid_card_details', true],
        ['lost card', FailureCategory::Customer, 'card_reported_lost', false],
        ['stolen card', FailureCategory::Customer, 'card_reported_lost', false],
        ['pick up card', FailureCategory::Customer, 'card_reported_lost', false],
        ['fraud', FailureCategory::Customer, 'suspected_fraud', false],
        ['restricted card', FailureCategory::Customer, 'card_restricted', false],
        ['not permitted', FailureCategory::Customer, 'card_restricted', false],
        ['exceeds withdrawal', FailureCategory::Customer, 'limit_exceeded', true],
        ['limit exceeded', FailureCategory::Customer, 'limit_exceeded', true],
        ['transaction limit', FailureCategory::Customer, 'limit_exceeded', true],
        ['address verification', FailureCategory::Customer, 'avs_failed', true],
        // "Policy error, please contact your bank": the issuer refusing under
        // its own rules, with nothing more specific given.
        ['policy error', FailureCategory::Customer, 'bank_declined', true],

        // Mobile money verdicts: the operator's prose relayed by the gateway
        // in processor_response. Before the transport bucket, because "timed
        // out waiting for customer" is a customer who never approved, not a
        // gateway that hung, and before the generic "declined".
        ['not enough balance', FailureCategory::Customer, 'insufficient_funds', true],
        ['low balance', FailureCategory::Customer, 'insufficient_funds', true],
        // "Transaction Failed Reason: LOW_BALANCE_OR_PAYEE_LIMIT_REACHED_OR_NOT_ALLOWED"
        // (Flutterwave relaying MTN, production 2026-09-22). One operator code
        // for three causes, so the verdict is the customer's mobile money
        // provider refusing, and the sentence tells them to check balance and
        // limit. Underscored, so the spaced rules above never see it.
        ['low_balance_or_payee_limit', FailureCategory::Customer, 'mobile_money_refused', true],
        ['insufficient wallet', FailureCategory::Customer, 'insufficient_funds', true],
        ['daily limit', FailureCategory::Customer, 'limit_exceeded', true],
        ['exceeds the limit', FailureCategory::Customer, 'limit_exceeded', true],
        ['above the limit', FailureCategory::Customer, 'limit_exceeded', true],
        ['amount exceeds', FailureCategory::Customer, 'limit_exceeded', true],
        ['maximum amount', FailureCategory::Customer, 'limit_exceeded', true],
        ['wrong pin', FailureCategory::Customer, 'incorrect_pin', true],
        // Only the customer's own refusal of the prompt. "Declined by
        // issuer" is a bank decline and stays one.
        ['did not approve', FailureCategory::Customer, 'prompt_declined', true],
        ['not approved by', FailureCategory::Customer, 'prompt_declined', true],
        ['rejected by customer', FailureCategory::Customer, 'prompt_declined', true],
        ['rejected by user', FailureCategory::Customer, 'prompt_declined', true],
        ['rejected by subscriber', FailureCategory::Customer, 'prompt_declined', true],
        ['declined by customer', FailureCategory::Customer, 'prompt_declined', true],
        ['declined by user', FailureCategory::Customer, 'prompt_declined', true],
        ['declined by subscriber', FailureCategory::Customer, 'prompt_declined', true],
        ['customer declined', FailureCategory::Customer, 'prompt_declined', true],
        ['user declined', FailureCategory::Customer, 'prompt_declined', true],
        ['subscriber declined', FailureCategory::Customer, 'prompt_declined', true],
        ['cancelled by customer', FailureCategory::Customer, 'prompt_declined', true],
        ['cancelled by user', FailureCategory::Customer, 'prompt_declined', true],
        ['canceled by customer', FailureCategory::Customer, 'prompt_declined', true],
        ['canceled by user', FailureCategory::Customer, 'prompt_declined', true],
        ['waiting for customer', FailureCategory::Customer, 'prompt_timed_out', true],
        ['did not respond', FailureCategory::Customer, 'prompt_timed_out', true],
        ['no response from', FailureCategory::Customer, 'prompt_timed_out', true],
        ['not authorized in time', FailureCategory::Customer, 'prompt_timed_out', true],
        ['authorization timed out', FailureCategory::Customer, 'prompt_timed_out', true],
        ['authorisation timed out', FailureCategory::Customer, 'prompt_timed_out', true],
        ['transaction expired', FailureCategory::Customer, 'prompt_timed_out', true],
        ['request expired', FailureCategory::Customer, 'prompt_timed_out', true],
        ['not registered', FailureCategory::Customer, 'mobile_account_not_found', true],
        ['unregistered', FailureCategory::Customer, 'mobile_account_not_found', true],
        ['no mobile money account', FailureCategory::Customer, 'mobile_account_not_found', true],
        ['invalid subscriber', FailureCategory::Customer, 'mobile_account_not_found', true],
        ['subscriber not found', FailureCategory::Customer, 'mobile_account_not_found', true],
        ['invalid msisdn', FailureCategory::Customer, 'mobile_account_not_found', true],
        ['invalid phone', FailureCategory::Customer, 'mobile_account_not_found', true],
        ['account not found', FailureCategory::Customer, 'mobile_account_not_found', true],

        // Crypto prose (NOWPayments messages)
        ['minimal amount', FailureCategory::Customer, 'below_minimum_amount', true],
        ['less than the minimal', FailureCategory::Customer, 'below_minimum_amount', true],

        // Customer behaviour
        ['insufficient balance', FailureCategory::Customer, 'insufficient_funds', true],
        ['closed modal', FailureCategory::Customer, 'user_cancelled', true],
        ['user cancelled', FailureCategory::Customer, 'user_cancelled', true],
        ['changed mind', FailureCategory::Customer, 'user_cancelled', true],
        ['abandoned', FailureCategory::Customer, 'user_cancelled', true],

        // Internal system markers ('orphaned_timeout' is a sweeper verdict,
        // not a gateway timeout — must outrank the provider bucket below)
        ['orphaned_timeout', FailureCategory::System, 'orphaned_timeout', true],
        // "Payment option currently undergoing maintenance updates. Please try
        // another": the rail is down on the gateway's side for a while. Its own
        // code so the dashboard can tell an outage from an ordinary error, and
        // ahead of the bare 'system' marker below so "system under maintenance"
        // is a provider outage, not a platform fault that pages somebody.
        ['undergoing maintenance', FailureCategory::Provider, 'provider_maintenance', true],
        ['under maintenance', FailureCategory::Provider, 'provider_maintenance', true],
        ['internal server error', FailureCategory::System, 'system_error', true],
        ['system', FailureCategory::System, 'system_error', true],

        // Gateway / transport
        ['timed out', FailureCategory::Provider, 'provider_timeout', true],
        ['timeout', FailureCategory::Provider, 'provider_timeout', true],
        ['could not resolve', FailureCategory::Provider, 'provider_unreachable', true],
        ['connection refused', FailureCategory::Provider, 'provider_unreachable', true],
        ['gateway unavailable', FailureCategory::Provider, 'provider_error', true],
        ['provider error', FailureCategory::Provider, 'provider_error', true],
        // "We are currently unable to process your request. Please try again
        // after some time": the gateway's generic busy answer. Only the first
        // half is the rule: "please try again" also ends bank declines.
        ['unable to process your request', FailureCategory::Provider, 'provider_error', true],
        ['currency not supported', FailureCategory::System, 'gateway_config_error', false],
        // "The currency for this payment is currently not supported": the
        // same refusal in the words the hosted checkout uses. We offered a
        // method in a currency the gateway will not take it in; ours. Tied to
        // the currency wording: "this card type is currently not supported"
        // is a different refusal and must not be filed as our configuration.
        ['currency for this payment is currently not supported', FailureCategory::System, 'gateway_config_error', false],
        // "phonenumber is required": a charge left our side without the
        // number the rail needs. Ours, never the customer's.
        ['phonenumber is required', FailureCategory::System, 'gateway_config_error', false],
        ['phone number is required', FailureCategory::System, 'gateway_config_error', false],
        // The gateway refusing a method/currency pairing we offered. This is
        // how every Ghana mobile-money attempt failed for weeks (2026-09-09)
        // while grading as "unknown": our integration asked for something the
        // rail does not do, and no customer retry can ever change that.
        ['not allowed for your currency', FailureCategory::System, 'gateway_config_error', false],
        ['payment option is not allowed', FailureCategory::System, 'gateway_config_error', false],
        ['invalid currency', FailureCategory::System, 'gateway_config_error', false],
        // "Transaction failed compliance checks": every Apple Pay attempt in
        // production got this (12 of 12 by 2026-09-10) although Apple Pay was
        // enabled on the account. The charge named nobody (no full name, phone
        // or country), which is what the gateway's compliance step wants.
        // Graded as ours so it alerts and never invites a retry; a genuine
        // per-customer block would arrive as a decline, not here.
        ['failed compliance check', FailureCategory::System, 'gateway_compliance_block', false],
        // A hosted-checkout init that returned a body with no message at all.
        ['unknown error', FailureCategory::Provider, 'provider_error', true],
        ['invalid api key', FailureCategory::System, 'gateway_config_error', false],
        ['authorization required', FailureCategory::System, 'gateway_config_error', false],

        // Generic decline LAST among card verdicts: only when nothing more
        // specific matched does "declined" become the verdict itself.
        ['declined', FailureCategory::Customer, 'bank_declined', true],
    ];

    /**
     * Frontend environment prose, kept from the original vocabulary.
     *
     * @var array<int, string>
     */
    private const FRONTEND_MARKERS = ['ad blocker', 'js exception', 'javascript', 'popup', 'network issue', 'sdk', 'offline', 'browser refresh', 'slow network'];

    /**
     * Failures that were ours, not the customer's. Recovery stays quiet on
     * them and insights:alert-failures raises a SEV-2 so a person writes
     * instead of a robot. The payment rate limiter still counts them as failed
     * attempts: retrying straight away does not fix them either.
     *
     * @var list<string>
     */
    public const PLATFORM_FAULT_CODES = ['gateway_config_error', 'gateway_compliance_block', 'system_error'];

    /**
     * Human labels for every reason code the taxonomy can emit, kept beside
     * the taxonomy so a new code cannot ship without its display name.
     *
     * @var array<string, string>
     */
    public const LABELS = [
        'insufficient_funds' => 'Insufficient funds',
        'do_not_honor' => 'Bank declined (no reason given)',
        'bank_declined' => 'Bank declined',
        'expired_card' => 'Expired card',
        'incorrect_pin' => 'Incorrect PIN',
        'authentication_failed' => 'OTP / 3DS authentication failed',
        'card_not_enrolled' => 'Card not enrolled for secure online (3-D Secure) payments',
        'invalid_card_details' => 'Invalid card details',
        'card_reported_lost' => 'Card reported lost or stolen',
        'suspected_fraud' => 'Flagged as suspected fraud',
        'card_restricted' => 'Card restricted for this purchase',
        'limit_exceeded' => 'Card limit exceeded',
        'avs_failed' => 'Address verification failed',
        'prompt_declined' => 'Customer declined the phone prompt',
        'prompt_timed_out' => 'Phone prompt not approved in time',
        'mobile_account_not_found' => 'No mobile money account for that number',
        'mobile_money_refused' => 'Mobile money provider refused (balance, limit or not allowed)',
        'user_cancelled' => 'Customer cancelled',
        'session_expired' => 'Session expired (abandoned)',
        'crypto_quote_expired' => 'Crypto quote expired unpaid',
        'crypto_underpaid' => 'Crypto payment underpaid',
        'below_minimum_amount' => 'Below gateway minimum amount',
        'refunded_by_gateway' => 'Refunded by gateway',
        'provider_timeout' => 'Gateway timed out',
        'provider_unreachable' => 'Gateway unreachable',
        'provider_error' => 'Gateway error',
        'provider_maintenance' => 'Payment option under maintenance at the gateway',
        'provider_rate_limited' => 'Gateway rate-limited us',
        'gateway_config_error' => 'Gateway configuration error (ours)',
        'gateway_compliance_block' => 'Gateway compliance check refused the charge',
        'orphaned_timeout' => 'Stuck session swept (webhook loss?)',
        'system_error' => 'Platform error',
        'no_outstanding_balance' => 'No outstanding balance',
        'missing_purchase_id' => 'Top-up missing purchase link',
        'frontend_issue' => 'Browser / device issue',
        'popup_blocked' => 'Payment popup blocked',
        'sdk_load_failed' => 'Payment SDK failed to load',
        'ad_blocker' => 'Ad blocker interference',
        'js_exception' => 'Browser script error',
        'network_offline' => 'Customer went offline',
        'network_timeout' => 'Customer network timed out',
        'slow_network' => 'Customer network too slow',
        'unrecognised_gateway_message' => 'Gateway message not recognised yet (add a rule)',
        'unknown_error' => 'Unknown (no diagnostic captured)',
    ];

    public static function label(string $code): string
    {
        return self::LABELS[$code] ?? str_replace('_', ' ', ucfirst($code));
    }

    /**
     * Classify a failure into category, stage, reason code and retryability.
     *
     * @param  array{provider_code?: ?string, provider_message?: ?string, http_status?: ?int}|null  $failure  GatewayFailure block when one was captured
     * @return array{category: FailureCategory, stage: FailureStage, code: string, retryable: bool, provider_code: ?string}
     */
    public function classify(string $provider, string $rawReason, string $sessionStatus, ?array $failure = null): array
    {
        $stage = $this->inferStage($sessionStatus);
        $providerCode = $this->normalise($failure['provider_code'] ?? null);
        $providerMessage = $this->normalise($failure['provider_message'] ?? null);
        $reason = $this->normalise($rawReason) ?? '';

        // 1 · Exact code tables, most trustworthy evidence first.
        foreach ([$providerCode, $reason] as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }

            $tables = self::INTERNAL_CODES + self::CLIENT_CODES
                + ($provider === 'nowpayments' ? self::NOWPAYMENTS_CODES : []);

            if (isset($tables[$candidate])) {
                [$category, $code, $retryable] = $tables[$candidate];

                return $this->verdict($category, $stage, $code, $retryable, $failure);
            }
        }

        // 2 · Prose patterns over the best text available: the gateway's own
        // words, and only when it left none, whatever reason we were handed.
        // The reason is the customer's sentence, and once the gateway has
        // spoken, that sentence is written FROM the verdict: grading it would
        // grade our own copy ("check your balance and daily limit" reads as a
        // limit failure) instead of admitting the gateway's words are new.
        $texts = [$providerCode, $providerMessage];
        if ($providerCode === null && $providerMessage === null) {
            $texts[] = $reason;
        }

        foreach ($texts as $text) {
            if ($text === null || $text === '') {
                continue;
            }

            foreach (self::PROSE_PATTERNS as [$needle, $category, $code, $retryable]) {
                if (str_contains($text, $needle)) {
                    return $this->verdict($category, $stage, $code, $retryable, $failure);
                }
            }
        }

        // 3 · Frontend environment vocabulary (original behaviour preserved).
        foreach (self::FRONTEND_MARKERS as $marker) {
            if (str_contains($reason, $marker)) {
                return $this->verdict(FailureCategory::Frontend, $stage, 'frontend_issue', true, $failure);
            }
        }

        // 4 · Transport-level heuristics when all we know is the HTTP status.
        $httpStatus = $failure['http_status'] ?? null;
        if (is_int($httpStatus)) {
            if ($httpStatus === 429) {
                return $this->verdict(FailureCategory::Provider, $stage, 'provider_rate_limited', true, $failure);
            }
            if ($httpStatus >= 500) {
                return $this->verdict(FailureCategory::Provider, $stage, 'provider_error', true, $failure);
            }
            if (in_array($httpStatus, [401, 403], true)) {
                return $this->verdict(FailureCategory::System, $stage, 'gateway_config_error', false, $failure);
            }
        }

        // 5 · Nothing matched. Two very different situations used to share one
        // bucket here: the gateway told us exactly what happened in words this
        // table has no rule for yet, and we captured nothing at all. The first
        // is a to-do item for whoever reads the dashboard (add the rule, run
        // insights:reclassify); the second is a diagnostic gap. Labelling both
        // "no diagnostic captured" hid the Ghana rail failure in plain sight.
        if ($providerMessage !== null || $providerCode !== null) {
            return $this->verdict(FailureCategory::System, $stage, 'unrecognised_gateway_message', true, $failure);
        }

        return $this->verdict(FailureCategory::System, $stage, 'unknown_error', true, $failure);
    }

    /**
     * @return array{category: FailureCategory, stage: FailureStage, code: string, retryable: bool, provider_code: ?string}
     */
    private function verdict(FailureCategory $category, FailureStage $stage, string $code, bool $retryable, ?array $failure): array
    {
        return [
            'category' => $category,
            'stage' => $stage,
            'code' => $code,
            'retryable' => $retryable,
            'provider_code' => $failure['provider_code'] ?? null,
        ];
    }

    private function normalise(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtolower(trim($value));

        return $value === '' ? null : preg_replace('/\s+/', ' ', $value);
    }

    private function inferStage(string $sessionStatus): FailureStage
    {
        return match ($sessionStatus) {
            'pending', 'awaiting_method' => FailureStage::Initialization,
            'awaiting_customer_action' => FailureStage::Authentication,
            'processing' => FailureStage::Processing,
            'awaiting_transfer', 'awaiting_confirmation' => FailureStage::Confirmation,
            default => FailureStage::Processing,
        };
    }
}
