<?php

use App\Domain\Risk\Signals\AccountBannedSignal;
use App\Domain\Risk\Signals\AccountNewSignal;
use App\Domain\Risk\Signals\AccountYoungSignal;
use App\Domain\Risk\Signals\ChargebackHistorySignal;
use App\Domain\Risk\Signals\ChargebackRepeatSignal;
use App\Domain\Risk\Signals\ConfirmedFraudLabelSignal;
use App\Domain\Risk\Signals\DeviceSharedSignal;
use App\Domain\Risk\Signals\DeviceUntrustedSignal;
use App\Domain\Risk\Signals\EmailPhoneSimilaritySignal;
use App\Domain\Risk\Signals\EmailUnverifiedSignal;
use App\Domain\Risk\Signals\HighRiskCategorySignal;
use App\Domain\Risk\Signals\HighValueSignal;
use App\Domain\Risk\Signals\IpSharedSignal;
use App\Domain\Risk\Signals\KycTierBelowRequiredSignal;
use App\Domain\Risk\Signals\PaymentInstrumentReuseSignal;
use App\Domain\Risk\Signals\RepeatedDeclinesSignal;
use App\Domain\Risk\Signals\TurnstileFailuresSignal;
use App\Domain\Risk\Signals\UnverifiedHighValueSignal;
use App\Domain\Risk\Signals\VelocityAmount24hSignal;
use App\Domain\Risk\Signals\VelocityDevice1hSignal;
use App\Domain\Risk\Signals\VelocityIp1hSignal;
use App\Domain\Risk\Signals\VelocityUser1hSignal;
use App\Domain\Risk\Signals\VelocityUser24hSignal;

return [

    /*
    |--------------------------------------------------------------------------
    | Policy version
    |--------------------------------------------------------------------------
    |
    | Stamped onto every risk_assessments row so a decision can always be
    | reproduced against the exact weights/cutoffs that produced it. Bump this
    | whenever weights, cutoffs, the band->outcome map, or the override list
    | change, so shadow data from an old policy is never confused with a new one.
    | (Champion/challenger comparison in Stage 3 keys off this string.)
    |
    */
    'policy_version' => env('RISK_POLICY_VERSION', '2026.09.13'),

    /*
    |--------------------------------------------------------------------------
    | Registered signals
    |--------------------------------------------------------------------------
    |
    | The ordered set the engine runs on every assess(). Each is a small,
    | independently testable unit; adding or removing one here is the only wiring
    | needed. Weights live in the 'weights' map below (keyed by each signal's
    | code), NOT on the class, so the whole policy is tunable from config with no
    | code change. Linkage/graph signals (DEVICE_SHARED, IP_SHARED, ...) land in
    | Stage 3 and are appended here then.
    |
    | @var list<class-string<\App\Domain\Risk\Contracts\RiskSignal>>
    */
    'signals' => [
        // Identity & account
        AccountNewSignal::class,
        AccountYoungSignal::class,
        EmailUnverifiedSignal::class,
        // DORMANT until the phone-OTP flow ships: phone numbers are captured at
        // registration but never verified, so this would fire for 100% of
        // customers today (pure noise). Re-register + restore its weight the day
        // phone verification exists.
        // \App\Domain\Risk\Signals\PhoneUnverifiedSignal::class,
        KycTierBelowRequiredSignal::class,

        // Value & category
        HighValueSignal::class,
        UnverifiedHighValueSignal::class,
        HighRiskCategorySignal::class,

        // Velocity
        VelocityUser1hSignal::class,
        VelocityUser24hSignal::class,
        VelocityIp1hSignal::class,
        VelocityDevice1hSignal::class,
        VelocityAmount24hSignal::class,

        // Payment behaviour
        RepeatedDeclinesSignal::class,
        TurnstileFailuresSignal::class,

        // Device & session
        DeviceUntrustedSignal::class,

        // Linkage / graph (Stage 3): multi-account abuse built from our own data.
        DeviceSharedSignal::class,
        IpSharedSignal::class,
        EmailPhoneSimilaritySignal::class,
        PaymentInstrumentReuseSignal::class,
        // DORMANT by CTO decision (2026-08-14): the country-change proxy is too
        // noisy (VPN/roaming, IP-geo accuracy) until a real GeoIP distance/time
        // provider is wired. Class + tests kept; re-register to re-enable.
        // \App\Domain\Risk\Signals\GeoVelocitySignal::class,

        // Feedback loop (Stage 3): scored history of past chargebacks. History
        // fires at one dispute; Repeat stacks on top at two or more, so a pattern
        // reaches the review band on its own.
        ChargebackHistorySignal::class,
        ChargebackRepeatSignal::class,

        // Hard overrides (force an outcome regardless of score; see 'overrides')
        ConfirmedFraudLabelSignal::class,
        AccountBannedSignal::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Signal families (two-track scoring)
    |--------------------------------------------------------------------------
    |
    | The codes listed here are ABUSE signals: behaviour inconsistent with
    | legitimate use (card testing, bursts, multi-account linkage, dispute
    | history). Only the abuse subscore can push a decision into review or deny.
    |
    | Every code NOT listed is an IDENTITY/CONTEXT signal (newness, unverified
    | state, value, category, untrusted device). Those can raise the total score
    | into a step-up (identity verification the customer can self-clear) but are
    | capped there: a legitimate customer must never be held or declined merely
    | for being new. Overrides bypass both tracks.
    |
    | @var list<string>
    */
    'abuse_codes' => [
        'VELOCITY_USER_1H',
        'VELOCITY_USER_24H',
        'VELOCITY_IP_1H',
        'VELOCITY_DEVICE_1H',
        'VELOCITY_AMOUNT_24H',
        'REPEATED_DECLINES',
        'TURNSTILE_FAILURES',
        'DEVICE_SHARED',
        'IP_SHARED',
        'EMAIL_PHONE_SIMILARITY',
        'PAYMENT_INSTRUMENT_REUSE',
        'GEO_VELOCITY',
        'CHARGEBACK_HISTORY',
        'CHARGEBACK_REPEAT',
        'CONFIRMED_FRAUD_LABEL',
        'ACCOUNT_BANNED',
    ],

    /*
    |--------------------------------------------------------------------------
    | Signal weights (points added to the score when the signal fires)
    |--------------------------------------------------------------------------
    |
    | Keyed by signal code. A missing code contributes 0, so a newly registered
    | signal is inert until it is given a weight here. Gift-card-heavy signals
    | (unverified high value, repeated declines = card testing) are weighted up.
    | These are STARTING weights to be tuned against shadow data before enforce.
    |
    | @var array<string, int>
    */
    'weights' => [
        // Identity / context family: weak-to-moderate evidence. Being new,
        // unverified, or high-value is a reason to VERIFY someone, never to hold
        // or refuse them; the engine caps this family at step_up.
        'ACCOUNT_NEW' => 6,
        'ACCOUNT_YOUNG' => 3,
        // Email verification is enforced at checkout/funding for logged-in
        // customers, so a fire here means a guest (currently disabled) or a gate
        // bypass; both deserve the full weight.
        'EMAIL_UNVERIFIED' => 25,
        // 'PHONE_UNVERIFIED' => 8,   // dormant until the phone-OTP flow ships
        'KYC_TIER_BELOW_REQUIRED' => 20,

        'HIGH_VALUE' => 10,
        'UNVERIFIED_HIGH_VALUE' => 30,
        'HIGH_RISK_CATEGORY' => 6,

        'DEVICE_UNTRUSTED' => 5,

        // Abuse family: behaviour inconsistent with legitimate use. Only these
        // reach review/deny (see 'abuse_codes'). Card-testing and instrument
        // reuse stay heaviest; IP-derived signals stay light because NG/CM
        // carrier CGNAT puts thousands of legitimate customers behind one IP.
        'VELOCITY_USER_1H' => 20,
        'VELOCITY_USER_24H' => 12,
        'VELOCITY_IP_1H' => 12,
        'VELOCITY_DEVICE_1H' => 20,
        'VELOCITY_AMOUNT_24H' => 20,

        'REPEATED_DECLINES' => 30,
        'TURNSTILE_FAILURES' => 12,

        'DEVICE_SHARED' => 25,
        'IP_SHARED' => 10,
        'EMAIL_PHONE_SIMILARITY' => 18,
        'PAYMENT_INSTRUMENT_REUSE' => 30,
        // 'GEO_VELOCITY' => 12,      // dormant by CTO decision, see 'signals'

        // Feedback loop: one dispute adds weight; a pattern (History + Repeat
        // stacking to 55) reaches the review band on its own.
        'CHARGEBACK_HISTORY' => 15,
        'CHARGEBACK_REPEAT' => 40,

        // Override signals still carry a nominal weight so the score reflects
        // them, but the override map is what actually forces the outcome.
        'CONFIRMED_FRAUD_LABEL' => 100,
        'ACCOUNT_BANNED' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Score bands
    |--------------------------------------------------------------------------
    |
    | Lower-inclusive cutoffs, evaluated high to low: a score >= cutoff lands in
    | that band. Anything below the lowest cutoff is LOW. Tunable without deploy.
    |
    | @var array<string, int>
    */
    'bands' => [
        'CRITICAL' => 80,
        'HIGH' => 55,
        'MEDIUM' => 30,
        'LOW' => 0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Band -> outcome map (two-track)
    |--------------------------------------------------------------------------
    |
    | The frictionless / challenge / decline model: allow, step_up (customer can
    | self-clear via identity verification), review (human queue), deny.
    |
    | The map is applied on TWO tracks:
    |  - TOTAL score (identity + abuse) resolves through this map but is CAPPED
    |    at step_up, so circumstantial signals can only ever ask the customer to
    |    verify, never hold or refuse them.
    |  - ABUSE subscore (abuse_codes only) resolves uncapped, so review/deny are
    |    reachable exclusively on evidence of abusive behaviour.
    | The most severe of the two wins; overrides can still force deny.
    |
    | @var array<string, string>
    */
    'band_outcomes' => [
        'LOW' => 'allow',
        'MEDIUM' => 'step_up',
        'HIGH' => 'review',
        'CRITICAL' => 'deny',
    ],

    /*
    |--------------------------------------------------------------------------
    | Hard overrides
    |--------------------------------------------------------------------------
    |
    | If any listed signal fires, its outcome is forced regardless of score or
    | band. The most severe forced outcome wins (deny > review > step_up). This is
    | how confirmed fraud, a banned account, or (later) a sanctions hit refuse
    | deterministically instead of relying on the score crossing a cutoff.
    |
    | @var array<string, string>
    */
    'overrides' => [
        'CONFIRMED_FRAUD_LABEL' => 'deny',
        'ACCOUNT_BANNED' => 'deny',
        // 'SANCTIONS_HIT' => 'deny',   // wired when AML screening lands
    ],

    /*
    |--------------------------------------------------------------------------
    | Thresholds and windows (per-signal tuning)
    |--------------------------------------------------------------------------
    |
    | All amounts are USD (settlement currency). Windows are hours. Counts are
    | "fires when the observed count is > the value" unless noted. The high-value
    | USD amount deliberately tracks the KYC Tier 2 threshold so a single knob
    | governs both, but can be split later if needed.
    |
    */
    'thresholds' => [
        // Account age
        'account_new_hours' => 24,
        'account_young_days' => 7,

        // Value (USD). Falls back to the KYC Tier 2 threshold when unset so the
        // two stay aligned by default.
        'high_value_usd' => env('RISK_HIGH_VALUE_USD'),

        // Velocity: fires when the count in the window EXCEEDS max. The IP bar
        // is deliberately high: NG/CM carrier CGNAT concentrates thousands of
        // legitimate customers behind single IPs.
        'velocity_user_1h_max' => 6,
        'velocity_user_24h_max' => 20,
        'velocity_ip_1h_max' => 25,
        'velocity_device_1h_max' => 8,

        // Cumulative spend spike: fires when paid volume in the window plus the
        // current amount reaches this USD figure.
        'velocity_amount_24h_usd' => 2000,

        // Repeated declines (card-testing): fires when decline_count in the
        // window reaches this many.
        'repeated_declines_window_hours' => 2,
        'repeated_declines_min' => 3,

        // Confirmed-fraud / chargeback labels lookback (days).
        'fraud_label_lookback_days' => 365,

        // Linkage / graph (Stage 3). "max_accounts" fires when the number of
        // DISTINCT accounts sharing the entity EXCEEDS the value. Thresholds are
        // set to spare legitimate sharing (a family card, an office IP) and
        // catch farms: 3+ accounts per card, 6+ accounts per IP in a day.
        //
        // Devices are stricter since 2026-09-13: the first card-testing pair
        // caught in production (two accounts, one phone, seven refused cards)
        // sat exactly one account under the old bar. Two accounts on one
        // device now adds weight; it never holds anyone on its own.
        'device_shared_max_accounts' => 1,
        'ip_shared_max_accounts' => 5,
        'ip_shared_window_hours' => 24,
        'instrument_reuse_max_accounts' => 2,
        'instrument_reuse_window_days' => 30,
        'similarity_max_accounts' => 1,

        // Geo-velocity: two distinct countries on one account inside this
        // window. ONE number, shared by the money path (GeoVelocitySignal) and
        // the login path (LoginCountryGuard), so the two can never disagree
        // about what counts as too fast to be a real journey. Six hours is
        // short enough that no real flight fits and long enough that nothing
        // routine does.
        'geo_velocity_window_hours' => 6,

        // How long a country the customer has proved stays proved before the
        // login check will ask about it again. Proof expires so an account
        // cannot accumulate a standing list of countries that silences the
        // check for life. 0 would mean forever.
        'login_country_proof_days' => 90,

        // Chargeback feedback: lookback for both signals; Repeat fires at this
        // many disputes or more.
        'chargeback_history_lookback_days' => 365,
        'chargeback_repeat_min' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | High-risk categories
    |--------------------------------------------------------------------------
    |
    | Product categories that carry elevated CNP risk (stored value / gift cards
    | are the classic card-testing target). HIGH_RISK_CATEGORY fires when the
    | action touches any of these. Matched against the category slugs the
    | RiskContext carries.
    |
    | @var list<string>
    */
    'high_risk_categories' => [
        'gift-cards',
    ],

    /*
    |--------------------------------------------------------------------------
    | Manual review queue
    |--------------------------------------------------------------------------
    |
    |  - sla_hours: the target for resolving a pending high-risk review. The admin
    |    queue surfaces anything older as breaching, and reports track it. Industry
    |    norm for high-risk CNP is same-day.
    |  - clearance_hours: once an admin APPROVES a review, the customer's retry is
    |    allowed for this window without re-triggering another review, so a cleared
    |    customer can actually complete the action they were held on.
    |
    */
    'review' => [
        'sla_hours' => 12,
        'clearance_hours' => 24,

        // Optional email recipients for new reviews, comma-separated in
        // RISK_REVIEW_OWNERS. The primary alert is the SEV-2 Telegram message
        // every opened review sends through OperationalAlerter, so a held
        // order never waits in an inbox; email is an extra, not the default.
        'owners' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('RISK_REVIEW_OWNERS', '')),
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | How long risk data is kept before the Stage 3 prune job removes it. 24
    | months covers dispute + audit needs; documented for the compliance file.
    |
    */
    'retention_months' => 24,
];
