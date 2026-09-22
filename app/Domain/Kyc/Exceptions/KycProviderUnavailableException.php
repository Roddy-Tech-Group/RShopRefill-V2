<?php

namespace App\Domain\Kyc\Exceptions;

use RuntimeException;

/**
 * The identity provider refused to start a verification session. Carries the
 * provider's own error code and text so the refusal is logged as it was said,
 * instead of surfacing later as a session with no token.
 */
class KycProviderUnavailableException extends RuntimeException
{
    public function __construct(
        public readonly ?string $providerCode = null,
        public readonly ?string $providerMessage = null,
    ) {
        parent::__construct(sprintf(
            'Identity provider refused to start a session (%s): %s',
            $providerCode ?? 'no code',
            $providerMessage ?? 'no message',
        ));
    }
}
