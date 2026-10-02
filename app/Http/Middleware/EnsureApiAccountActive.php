<?php

namespace App\Http\Middleware;

use App\Domain\Security\Services\ApiTokenService;
use App\Models\User;
use App\Support\ApiV2Error;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks a token belonging to an account that has been banned or closed.
 *
 * EnsureAccountActive does this for the website, but it reads $request->user('web')
 * and invalidates a session, so it is not part of the stateless api-v2 group at all.
 * Without this counterpart, banning a customer would stop them on the website and
 * leave their phone working until the token happened to expire.
 *
 * The tokens are revoked rather than merely refused, so the app is forced back to
 * the login screen instead of retrying with something that will never work again.
 */
class EnsureApiAccountActive
{
    public function __construct(private ApiTokenService $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $reason = match (true) {
            $user->banned_at !== null => ['account_banned', 'Your account has been banned. Please contact support.'],
            $user->anonymised_at !== null => ['account_closed', 'This account has been closed.'],
            default => null,
        };

        if ($reason === null) {
            return $next($request);
        }

        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $this->tokens->revokeCurrentDevice($token);
        }

        return ApiV2Error::make($reason[0], $reason[1], 403);
    }
}
