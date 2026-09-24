<?php

namespace App\Domain\Payment\Support;

/**
 * Single source of truth for whether the payment and fulfilment stack is
 * allowed to fall back to mock gateway credentials. Mock mode is permitted
 * ONLY in local/testing, or when explicitly opted in via PAYMENT_MOCK=true
 * outside production. In production it is never permitted, whatever the
 * environment says: a missing real credential must hard-fail rather than
 * silently process payments, or hand out codes, against a fake gateway.
 */
final class MockMode
{
    public static function allowed(): bool
    {
        if (app()->environment('production')) {
            return false;
        }

        return app()->environment(['local', 'testing'])
            || (bool) config('services.payment_mock', false);
    }

    /**
     * Whether a provider holding this credential runs against its mock.
     *
     * A blank credential, or one carrying the MOCK naming convention the test
     * suite uses, mocks only where mocking is allowed at all. In production a
     * blank credential has already refused to start (see the providers), and
     * a real key that happens to contain "MOCK" is just a real key.
     */
    public static function forKey(?string $key): bool
    {
        if (! self::allowed()) {
            return false;
        }

        $key = trim((string) $key);

        return $key === '' || str_contains($key, 'MOCK');
    }
}
