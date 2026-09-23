<?php

namespace App\Models;

use App\Domain\Catalog\Enums\CatalogCleanupSkipReason;
use App\Domain\Catalog\Enums\CatalogFeed;
use App\Domain\Catalog\Enums\CatalogSyncRunStatus;
use Database\Factories\CatalogSyncRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One full pass over a supplier catalog feed. Offers the pass did not see are
 * hidden only when the run completes cleanly and passes the shrink guards; the
 * row keeps what was seen, hidden and restored so every cleanup is on record.
 */
class CatalogSyncRun extends Model
{
    /** @use HasFactory<CatalogSyncRunFactory> */
    use HasFactory;

    protected $fillable = [
        'feed',
        'status',
        'force_cleanup',
        'started_at',
        'finished_at',
        'pages',
        'offers_seen',
        'items_failed',
        'cleanup',
        'cleanup_skipped_reason',
        'offers_delisted',
        'offers_restored',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'feed' => CatalogFeed::class,
            'status' => CatalogSyncRunStatus::class,
            'force_cleanup' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'pages' => 'integer',
            'offers_seen' => 'integer',
            'items_failed' => 'integer',
            'cleanup_skipped_reason' => CatalogCleanupSkipReason::class,
            'offers_delisted' => 'integer',
            'offers_restored' => 'integer',
        ];
    }

    public function isRunning(): bool
    {
        return $this->status === CatalogSyncRunStatus::Running;
    }

    /**
     * One line for command output: what the run saw and what its cleanup did.
     */
    public function summary(): string
    {
        $seen = "Run #{$this->id} ({$this->feed->value}) {$this->status->value}: {$this->offers_seen} offers seen";

        return match (true) {
            $this->status === CatalogSyncRunStatus::Failed => "{$seen}; no offers hidden ({$this->failure_reason}).",
            $this->cleanup === 'applied' => "{$seen}; {$this->offers_delisted} no longer listed and hidden, {$this->offers_restored} back on sale.",
            $this->cleanup === 'skipped' => "{$seen}; cleanup skipped ({$this->cleanup_skipped_reason?->value}), missing offers left on sale and an alert sent.",
            $this->cleanup === 'preview' => $this->cleanup_skipped_reason !== null
                ? "{$seen}; dry run, and the guards would have held the cleanup back ({$this->cleanup_skipped_reason->value}). Nothing was changed."
                : "{$seen}; dry run, {$this->offers_delisted} would be hidden and {$this->offers_restored} put back on sale. Nothing was changed.",
            default => "{$seen}.",
        };
    }
}
