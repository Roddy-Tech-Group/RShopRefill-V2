<?php

namespace App\Domain\Risk\DTOs;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Everything a signal might need to judge one money action, assembled once at the
 * choke point and passed to every signal. Signals read from this and their own
 * data source only; they never touch the request or globals, which is what makes
 * them pure and unit-testable in isolation.
 *
 * Built either explicitly (tests, queued paths) or via capture(), which lifts IP
 * and device fingerprint off the current HTTP request when one exists.
 */
final readonly class RiskContext
{
    /**
     * @param  string  $context  'order' | 'funding' | 'pay' | 'login'
     * @param  list<string>  $categories  category slugs the action touches
     * @param  string|null  $subjectType  morph-ish type of the thing assessed (order/funding/session)
     * @param  bool  $bringsNewMoney  false when the action spends balance the
     *                                customer already holds (an order paid from
     *                                wallet); KYC's cumulative money-in count
     *                                does not add such an action to itself
     */
    public function __construct(
        public ?User $user,
        public string $context,
        public float $amountUsd = 0.0,
        public ?string $currency = null,
        public ?string $ip = null,
        public ?string $deviceUuid = null,
        public array $categories = [],
        public ?string $subjectType = null,
        public ?string $subjectId = null,
        public ?string $correlationId = null,
        public bool $bringsNewMoney = true,
    ) {}

    /**
     * Assemble a context for a money action, capturing IP + device off the
     * current request when one is bound (the checkout/funding paths always run
     * inside a request). Falls back to nulls in console/queue contexts, where the
     * request-derived signals simply do not fire.
     *
     * @param  string  $context  'order' | 'funding' | 'pay'
     * @param  list<string>  $categories
     */
    public static function capture(
        ?User $user,
        string $context,
        float $amountUsd,
        ?string $currency = null,
        array $categories = [],
        ?string $subjectType = null,
        ?string $subjectId = null,
        bool $bringsNewMoney = true,
    ): self {
        $request = app()->bound('request') ? app('request') : null;
        $ip = null;
        $deviceUuid = null;

        if ($request instanceof Request) {
            $ip = $request->ip();
            $deviceUuid = $request->header('X-Installation-ID', $request->cookie('device_uuid'));
            $deviceUuid = is_string($deviceUuid) && $deviceUuid !== '' ? $deviceUuid : null;
        }

        return new self(
            user: $user,
            context: $context,
            amountUsd: $amountUsd,
            currency: $currency,
            ip: $ip,
            deviceUuid: $deviceUuid,
            categories: $categories,
            subjectType: $subjectType,
            subjectId: $subjectId,
            correlationId: $request instanceof Request ? $request->header('X-Correlation-ID') : null,
            bringsNewMoney: $bringsNewMoney,
        );
    }

    public function isGuest(): bool
    {
        return $this->user === null || (bool) ($this->user->is_guest ?? false);
    }
}
