<?php

namespace App\Console\Commands;

use App\Domain\Security\Services\ApiTokenService;
use App\Models\ApiIdempotencyKey;
use App\Models\ApiRefreshToken;
use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Clears out the mobile API's short-lived bookkeeping.
 *
 * Two tables grow with every app session and neither is interesting once it is
 * past its window:
 *
 *  - api_idempotency_keys: one row per money-moving tap, replayable for 24 hours.
 *    Left alone this is the fastest-growing table we own, because a busy day is
 *    thousands of rows that stopped mattering the same afternoon.
 *  - api_refresh_tokens: kept for a while after expiring or being revoked, on
 *    purpose, so a support question about a surprise sign-out can still be
 *    answered. Only rows well past that are removed.
 *
 * Neither deletion can lose anything a customer or an auditor needs: orders,
 * payments and the wallet ledger are untouched.
 */
class PruneApiArtifactsCommand extends Command
{
    protected $signature = 'api:prune
        {--keep-token-days=30 : How long to keep dead refresh tokens for support}
        {--dry-run : Report what would be removed and change nothing}';

    protected $description = 'Prune expired mobile API idempotency keys and dead refresh tokens';

    public function handle(ApiTokenService $tokens): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $keepDays = max(1, (int) $this->option('keep-token-days'));

        $expiredKeys = ApiIdempotencyKey::where('expires_at', '<', now());

        if ($dryRun) {
            $this->components->twoColumnDetail('Idempotency keys expired', (string) $expiredKeys->count());
            $this->components->twoColumnDetail(
                'Refresh tokens prunable',
                (string) $this->prunableRefreshTokenCount($keepDays),
            );
            $this->components->twoColumnDetail(
                'Access tokens prunable',
                (string) $this->prunableAccessTokenCount($keepDays),
            );
            $this->components->info('Dry run: nothing was removed.');

            return self::SUCCESS;
        }

        $keysRemoved = $expiredKeys->delete();
        $tokensRemoved = $tokens->prune($keepDays);

        $this->components->twoColumnDetail('Idempotency keys removed', (string) $keysRemoved);
        $this->components->twoColumnDetail('Refresh tokens removed', (string) $tokensRemoved['refresh']);
        $this->components->twoColumnDetail('Access tokens removed', (string) $tokensRemoved['access']);

        return self::SUCCESS;
    }

    /**
     * Mirrors ApiTokenService::prune's window without deleting, so --dry-run
     * reports the same set the real run would remove.
     */
    private function prunableRefreshTokenCount(int $keepDays): int
    {
        $cutoff = now()->subDays($keepDays);

        return ApiRefreshToken::where('expires_at', '<', $cutoff)
            ->where(function ($query) use ($cutoff) {
                $query->whereNull('revoked_at')->orWhere('revoked_at', '<', $cutoff);
            })
            ->count();
    }

    private function prunableAccessTokenCount(int $keepDays): int
    {
        return PersonalAccessToken::where('expires_at', '<', now()->subDays($keepDays))->count();
    }
}
