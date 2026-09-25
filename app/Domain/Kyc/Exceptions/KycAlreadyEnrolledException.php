<?php

namespace App\Domain\Kyc\Exceptions;

/**
 * The provider already holds an enrollment for this customer, so it will not
 * run another one. An enrollment only exists after an earlier verification
 * passed at the provider, which means the right answer is to read that result
 * back, not to send the customer through the flow again.
 */
class KycAlreadyEnrolledException extends KycProviderUnavailableException {}
