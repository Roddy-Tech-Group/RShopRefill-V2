<?php

namespace App\Domain\Auth\Exceptions;

use RuntimeException;

/**
 * Raised when an account cannot be closed yet (money still in the wallet, an
 * order still in flight). The message is written for the customer.
 */
class AccountClosureException extends RuntimeException {}
