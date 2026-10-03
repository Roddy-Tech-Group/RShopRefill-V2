<?php

namespace App\Domain\Auth\Exceptions;

use RuntimeException;

/**
 * Google could not be reached, or answered with an error of its own. Nothing is
 * known about the token either way, so the right move is to try again shortly,
 * which is a different screen from "sign in again".
 */
class GoogleUnavailableException extends RuntimeException {}
