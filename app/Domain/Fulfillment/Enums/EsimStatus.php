<?php

namespace App\Domain\Fulfillment\Enums;

/**
 * An eSIM's state on the Manage eSIMs page. Every state but Refund pending and
 * Refunded is the supplier's own, read from its usage endpoint; DataLow is Active with a
 * quarter or less of the data left, the point the supplier's own 75% usage
 * webhook fires.
 */
enum EsimStatus: string
{
    case Inactive = 'inactive';
    case Active = 'active';
    case DataLow = 'data_low';
    case DataUsedUp = 'data_used_up';
    case Expired = 'expired';
    case Recycled = 'recycled';
    case RefundPending = 'refund_pending';
    case Refunded = 'refunded';
    case Unknown = 'unknown';

    /** Share of the data left at or under which an active eSIM is running low. */
    public const LOW_DATA_SHARE = 0.25;

    public function label(): string
    {
        return match ($this) {
            self::Inactive => 'Inactive',
            self::Active => 'Active',
            self::DataLow => 'Data running low',
            self::DataUsedUp => 'Data used up',
            self::Expired => 'Expired',
            self::Recycled => 'Recycled',
            self::RefundPending => 'Refund pending',
            self::Refunded => 'Refunded',
            self::Unknown => 'Unknown',
        };
    }

    /**
     * Badge tone for the admin tables.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Active => 'emerald',
            self::DataLow, self::RefundPending => 'amber',
            self::Inactive => 'blue',
            self::Refunded => 'purple',
            self::DataUsedUp, self::Expired, self::Recycled, self::Unknown => 'zinc',
        };
    }

    /**
     * Map the supplier's status (NOT_ACTIVE, ACTIVE, FINISHED, EXPIRED,
     * RECYCLED, UNKNOWN) to ours.
     */
    public static function fromProvider(?string $providerStatus, ?int $remainingMb, ?int $totalMb, bool $isUnlimited): self
    {
        $status = match (strtoupper((string) $providerStatus)) {
            'NOT_ACTIVE' => self::Inactive,
            'ACTIVE' => self::Active,
            'FINISHED' => self::DataUsedUp,
            'EXPIRED' => self::Expired,
            'RECYCLED' => self::Recycled,
            default => self::Unknown,
        };

        if ($status === self::Active && ! $isUnlimited && $totalMb > 0 && $remainingMb !== null
            && $remainingMb / $totalMb <= self::LOW_DATA_SHARE) {
            return self::DataLow;
        }

        return $status;
    }
}
