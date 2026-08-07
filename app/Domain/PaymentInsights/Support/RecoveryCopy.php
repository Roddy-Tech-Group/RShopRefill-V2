<?php

namespace App\Domain\PaymentInsights\Support;

/**
 * Turns a classified failure reason into the words the customer reads.
 *
 * A customer whose bank refused for insufficient funds and one whose browser
 * blocked the payment popup have nothing in common except that no money
 * moved. Sending both "your payment was not completed" wastes the only
 * message we get: the person already knows it failed, what they need is what
 * to do differently. Every entry therefore carries a concrete next action.
 *
 * Rules for the copy itself:
 *  - Never name the gateway or quote its raw text (leaks internals, reads as
 *    gibberish, and tells a fraudster which control caught them).
 *  - Never blame the customer's bank in a way we cannot substantiate; say
 *    what happened and what helps.
 *  - Never promise a retry will work when the reason says it cannot; those
 *    failures are excluded from recovery upstream, so anything reaching this
 *    class is genuinely retryable.
 */
final class RecoveryCopy
{
    /**
     * reason code => [subject, headline, guidance, action hint]
     *
     * @var array<string, array{subject: string, headline: string, guidance: string, hint: ?string}>
     */
    private const COPY = [
        'insufficient_funds' => [
            'subject' => 'Your payment needs another try',
            'headline' => 'Your bank turned down the payment',
            'guidance' => 'The card was declined for insufficient funds, so nothing was charged. Once the funds are available you can pick up exactly where you left off.',
            'hint' => 'You can also pay from your wallet balance or use a different card.',
        ],
        'do_not_honor' => [
            'subject' => 'Your bank declined the payment',
            'headline' => 'Your bank declined the payment',
            'guidance' => 'Your bank refused the charge without telling us why, which usually means a routine security block rather than a problem with your account. Nothing was charged.',
            'hint' => 'Approving the transaction with your bank, or using a different card or your wallet, normally clears it.',
        ],
        'bank_declined' => [
            'subject' => 'Your bank declined the payment',
            'headline' => 'Your bank declined the payment',
            'guidance' => 'The charge was declined by your bank and nothing was taken from your account.',
            'hint' => 'Trying a different card, or paying from your wallet balance, usually works.',
        ],
        'incorrect_pin' => [
            'subject' => 'Your payment needs another try',
            'headline' => 'That PIN was not accepted',
            'guidance' => 'The card PIN did not verify, so the payment stopped there and nothing was charged.',
            'hint' => 'Take care with the PIN on the next attempt; too many wrong tries can lock the card at your bank.',
        ],
        'authentication_failed' => [
            'subject' => 'Your payment needs another try',
            'headline' => 'The verification step did not go through',
            'guidance' => 'The OTP or 3D Secure check did not complete, so the payment was stopped before any money moved.',
            'hint' => 'Codes expire quickly, so it helps to have your phone to hand before you start.',
        ],
        'invalid_card_details' => [
            'subject' => 'Check your card details and try again',
            'headline' => 'Those card details did not validate',
            'guidance' => 'The card number, expiry or CVV did not check out, so nothing was charged.',
            'hint' => 'Re-enter the details straight from the card, or use another payment method.',
        ],
        'limit_exceeded' => [
            'subject' => 'Your card limit stopped this payment',
            'headline' => 'Your card limit was reached',
            'guidance' => 'Your bank blocked the charge because it would pass a spending or transfer limit. Nothing was charged.',
            'hint' => 'A smaller amount, a different card, or your wallet balance will get around it.',
        ],
        'avs_failed' => [
            'subject' => 'Check your card details and try again',
            'headline' => 'The billing address check failed',
            'guidance' => 'Your bank could not match the billing address to the card, so the charge was declined.',
            'hint' => 'Use the address exactly as your bank has it, or try another method.',
        ],
        'crypto_quote_expired' => [
            'subject' => 'Your crypto quote expired',
            'headline' => 'The quote ran out before the coins landed',
            'guidance' => 'Crypto quotes are only held for a short window because rates move. Yours expired before the transfer confirmed, so the order was not completed.',
            'hint' => 'Start a fresh quote and send within the countdown; network fees can be worth checking so the transfer confirms in time.',
        ],
        'crypto_underpaid' => [
            'subject' => 'Your crypto payment came up short',
            'headline' => 'We received less than the invoice',
            'guidance' => 'The amount that arrived was under the invoiced total, usually because network fees were taken from the transfer rather than added to it.',
            'hint' => 'You can top up the difference to complete the order.',
        ],
        'below_minimum_amount' => [
            'subject' => 'That amount was below the minimum',
            'headline' => 'The amount was under the network minimum',
            'guidance' => 'The payment network has a minimum for this coin and the amount fell below it, so the payment could not be created.',
            'hint' => 'A larger amount, or a different coin with a lower minimum, will go through.',
        ],
        'provider_timeout' => [
            'subject' => 'That payment attempt did not go through',
            'headline' => 'Our payment partner timed out',
            'guidance' => 'This one was not your fault. The payment network did not answer in time and the attempt was stopped safely, with nothing charged.',
            'hint' => 'It normally clears within minutes.',
        ],
        'provider_unreachable' => [
            'subject' => 'That payment attempt did not go through',
            'headline' => 'We could not reach our payment partner',
            'guidance' => 'This one was not your fault. The payment network was unreachable when you tried, and the attempt was stopped safely with nothing charged.',
            'hint' => 'It normally clears within minutes.',
        ],
        'provider_error' => [
            'subject' => 'That payment attempt did not go through',
            'headline' => 'Our payment partner had a problem',
            'guidance' => 'This one was not your fault. The payment network returned an error and the attempt was stopped safely, with nothing charged.',
            'hint' => 'It normally clears quickly.',
        ],
        'provider_rate_limited' => [
            'subject' => 'That payment attempt did not go through',
            'headline' => 'The payment network was busy',
            'guidance' => 'This one was not your fault. Traffic to the payment network was throttled at that moment and the attempt was stopped safely, with nothing charged.',
            'hint' => 'A minute or two is usually all it takes.',
        ],
        'system_error' => [
            'subject' => 'That payment attempt did not go through',
            'headline' => 'Something went wrong on our side',
            'guidance' => 'This one was on us, not you. The payment was stopped before any money moved, and the problem has been logged for our team.',
            'hint' => null,
        ],
        'orphaned_timeout' => [
            'subject' => 'Your payment was not completed',
            'headline' => 'That payment was left hanging',
            'guidance' => 'The payment never reached a final answer, so we closed it out rather than leave it in limbo. Nothing was charged.',
            'hint' => null,
        ],
        'popup_blocked' => [
            'subject' => 'Your browser blocked the payment window',
            'headline' => 'The payment window was blocked',
            'guidance' => 'Your browser stopped the secure payment window from opening, so the payment never started.',
            'hint' => 'Allowing pop-ups for our site, or using a different browser, sorts it out.',
        ],
        'sdk_load_failed' => [
            'subject' => 'The payment form did not load',
            'headline' => 'The payment form did not load',
            'guidance' => 'The secure payment form could not load in your browser, so the payment never started.',
            'hint' => 'Refreshing, switching browser, or moving off a restricted network usually fixes it.',
        ],
        'ad_blocker' => [
            'subject' => 'Your browser blocked the payment window',
            'headline' => 'A browser extension blocked the payment',
            'guidance' => 'An ad blocker or privacy extension stopped the secure payment form from loading, so the payment never started.',
            'hint' => 'Pausing the extension for our site, or using a different browser, resolves it.',
        ],
        'js_exception' => [
            'subject' => 'The payment form hit an error',
            'headline' => 'The payment page hit an error',
            'guidance' => 'Something broke in the payment page before it could take your details, so nothing was charged.',
            'hint' => 'A refresh, or a different browser, normally clears it.',
        ],
        'network_offline' => [
            'subject' => 'Your connection dropped mid-payment',
            'headline' => 'The connection dropped',
            'guidance' => 'Your device went offline while the payment was being set up, so it never completed.',
            'hint' => null,
        ],
        'network_timeout' => [
            'subject' => 'Your connection dropped mid-payment',
            'headline' => 'The connection timed out',
            'guidance' => 'Your connection timed out while the payment was being set up, so it never completed.',
            'hint' => null,
        ],
        'slow_network' => [
            'subject' => 'Your connection dropped mid-payment',
            'headline' => 'The connection was too slow',
            'guidance' => 'The payment could not finish setting up before the connection gave out, so nothing was charged.',
            'hint' => null,
        ],
        'frontend_issue' => [
            'subject' => 'Your browser stopped the payment',
            'headline' => 'Your browser stopped the payment',
            'guidance' => 'The payment could not start in your browser, so nothing was charged.',
            'hint' => 'A refresh or a different browser usually fixes it.',
        ],
        'session_expired' => [
            'subject' => 'Your order is still waiting',
            'headline' => 'Your checkout timed out',
            'guidance' => 'The checkout window closed before the payment was finished, so nothing was charged and your items are still here.',
            'hint' => null,
        ],
        'user_cancelled' => [
            'subject' => 'Your order is still waiting',
            'headline' => 'You left before finishing',
            'guidance' => 'The payment was cancelled before it completed, so nothing was charged. Everything is still saved if you want to pick it up.',
            'hint' => null,
        ],
    ];

    private const FALLBACK = [
        'subject' => 'Your payment was not completed',
        'headline' => 'Your payment was not completed',
        'guidance' => 'Your recent payment attempt did not go through, and nothing was charged.',
        'hint' => null,
    ];

    /**
     * @return array{subject: string, headline: string, guidance: string, hint: ?string}
     */
    public static function for(?string $reasonCode): array
    {
        return self::COPY[$reasonCode] ?? self::FALLBACK;
    }

    /**
     * Reminder copy is deliberately shorter and softer: this is the second
     * time we have written about the same failure, and a nag reads worse
     * than a nudge.
     */
    public static function reminderSubject(?string $reasonCode): string
    {
        return match ($reasonCode) {
            'insufficient_funds', 'do_not_honor', 'bank_declined', 'limit_exceeded' => 'Still want to complete your order?',
            'crypto_quote_expired', 'crypto_underpaid', 'below_minimum_amount' => 'Your crypto payment is still unfinished',
            default => 'Your order is still waiting for you',
        };
    }
}
