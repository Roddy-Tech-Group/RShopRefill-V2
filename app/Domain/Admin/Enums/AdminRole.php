<?php

namespace App\Domain\Admin\Enums;

use App\Models\Admin;

/**
 * Admin role definitions.
 *
 * Stored as string values in the database for readability and
 * future extensibility. New roles can be added without schema
 * changes — just add a new case and reference it in policies.
 */
enum AdminRole: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Moderator = 'moderator';

    /**
     * Human-readable label for display.
     */
    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Admin => 'Admin',
            self::Moderator => 'Moderator',
        };
    }

    /**
     * Whether this role has full unrestricted access.
     */
    public function isSuperAdmin(): bool
    {
        return $this === self::SuperAdmin;
    }

    /**
     * Default section permissions pre-filled when this role is chosen on the
     * admin form. A Super Admin can then tick/untick individual sections per
     * admin; the saved set is authoritative (see {@see Admin::canAccessSection}).
     * Super Admin is intentionally not listed - it always has unrestricted
     * access and cannot be scoped down.
     *
     * @return array<int, string>
     */
    public function defaultPermissions(): array
    {
        $all = array_keys(Admin::SECTIONS);

        return match ($this) {
            self::SuperAdmin => $all,
            // Everything except managing admins, integration keys and the
            // CTO-level Management section (finances, monitoring, runbooks) —
            // a Super Admin can still grant `management` per admin.
            self::Admin => array_values(array_diff($all, ['admins', 'api-settings', 'management'])),
            self::Moderator => ['content', 'support-tickets', 'newsletter'],
        };
    }
}
