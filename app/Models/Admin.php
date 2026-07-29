<?php

namespace App\Models;

use App\Domain\Admin\Enums\AdminRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Represents a platform administrator.
 *
 * Extends Authenticatable so it works seamlessly with Laravel's
 * auth system as a separate guard provider. Completely isolated
 * from the User model — different table, different guard,
 * different session.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property AdminRole $role
 * @property string|null $avatar_url
 * @property string $theme "light" | "dark" | "system"
 * @property bool $is_active
 * @property Carbon|null $last_login_at
 * @property string|null $remember_token
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Admin extends Authenticatable
{
    use HasFactory, Notifiable;

    /**
     * The table associated with the model.
     */
    protected $table = 'admins';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'permissions',
        'avatar_url',
        'theme',
        'is_active',
        'last_login_at',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'email_otp',
        'email_otp_expires_at',
        'security_question',
        'security_answer',
    ];

    /**
     * Assignable admin sections: the sidebar areas a Super Admin can grant per
     * admin from the edit form. The key is matched against route names by
     * {@see self::routeSection()}; the label is what the form checkbox shows.
     * Dashboard / account / activity are deliberately omitted - every admin
     * keeps those as self-service essentials.
     *
     * @var array<string, string>
     */
    public const SECTIONS = [
        'dashboard' => 'Dashboard',
        'products' => 'Products',
        'orders' => 'Orders',
        'customers' => 'Customers',
        'transactions' => 'Transactions',
        'wallets' => 'Wallets',
        'reports' => 'Reports',
        'newsletter' => 'Newsletter',
        'campaigns' => 'Campaigns',
        'pricing-rules' => 'Pricing Rules',
        'content' => 'Content (CMS)',
        'support-tickets' => 'Support Tickets',
        'notifications' => 'Notifications',
        'gift-cards' => 'Gift Cards Trading',
        'admins' => 'Admins',
        'rates' => 'Rate Management',
        'settings' => 'System Settings',
        'api-settings' => 'API & Integrations',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => AdminRole::class,
            'permissions' => 'array',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'email_otp_expires_at' => 'datetime',
        ];
    }

    // ────────────────────────────────────────────────────────────
    //  Helper Methods
    // ────────────────────────────────────────────────────────────

    /**
     * Check if this admin has the super_admin role.
     */
    public function isSuperAdmin(): bool
    {
        return $this->role === AdminRole::SuperAdmin;
    }

    /**
     * The sections this admin may access. A Super Admin always gets every
     * section. Otherwise it is the explicitly-saved permission set, falling
     * back to the role's default preset when the column is null (so existing
     * admins keep working until a Super Admin customises them).
     *
     * @return array<int, string>
     */
    public function accessibleSections(): array
    {
        if ($this->isSuperAdmin()) {
            return array_keys(self::SECTIONS);
        }

        $saved = $this->permissions;
        if (is_array($saved)) {
            return array_values(array_intersect($saved, array_keys(self::SECTIONS)));
        }

        return $this->role->defaultPermissions();
    }

    /**
     * Whether this admin may access a named section (one of self::SECTIONS).
     */
    public function canAccessSection(string $section): bool
    {
        return $this->isSuperAdmin() || in_array($section, $this->accessibleSections(), true);
    }

    /**
     * Whether this admin may open the given admin route name. Self-service
     * routes (dashboard, own account, activity, logout, theme) are always
     * allowed; everything else maps to a section and is checked against the
     * admin's accessible sections. Used by both the AdminAuth middleware and
     * the sidebar so the menu shows exactly what is reachable.
     */
    public function canAccessAdminRoute(string $routeName): bool
    {
        $name = str_starts_with($routeName, 'admin.') ? substr($routeName, 6) : $routeName;

        // Special rule: Moderators can never view individual customer profiles
        if ($this->role === AdminRole::Moderator) {
            if ($name === 'customer' || str_starts_with($name, 'customer.')) {
                return false;
            }
        }

        $alwaysAllowed = ['logout', 'theme', 'account', 'account-activity', 'operations', 'revert-impersonation'];
        if (in_array($name, $alwaysAllowed, true)) {
            return true;
        }

        $section = self::routeSection($name);

        // Unknown routes default to Super-Admin-only, so a new admin page can't
        // be silently exposed before it is mapped to a section here.
        if ($section === null) {
            return $this->isSuperAdmin();
        }

        return $this->canAccessSection($section);
    }

    /**
     * Map an admin route name (without the "admin." prefix) to its section key,
     * or null when it has no section (handled as Super-Admin-only).
     */
    public static function routeSection(string $name): ?string
    {
        return match (true) {
            str_starts_with($name, 'dashboard') || str_starts_with($name, 'api.dashboard.') => 'dashboard',
            str_starts_with($name, 'products') || str_starts_with($name, 'api.catalog.') => 'products',
            str_starts_with($name, 'orders') || $name === 'order' || str_starts_with($name, 'api.commerce.') => 'orders',
            str_starts_with($name, 'customers') || $name === 'customer' || str_starts_with($name, 'customer.') || str_starts_with($name, 'kyc.') => 'customers',
            str_starts_with($name, 'transactions') => 'transactions',
            str_starts_with($name, 'wallets') || str_starts_with($name, 'api.monitoring.') => 'wallets',
            str_starts_with($name, 'reports') || str_starts_with($name, 'api.sre.') => 'reports',
            str_starts_with($name, 'newsletter') => 'newsletter',
            str_starts_with($name, 'campaigns') => 'campaigns',
            str_starts_with($name, 'pricing-rules') => 'pricing-rules',
            str_starts_with($name, 'content.') || str_starts_with($name, 'api.rewards.') => 'content',
            str_starts_with($name, 'support-tickets') => 'support-tickets',
            str_starts_with($name, 'notifications') || str_starts_with($name, 'api.notifications.') => 'notifications',
            str_starts_with($name, 'gift-cards') => 'gift-cards',
            str_starts_with($name, 'admins') => 'admins',
            str_starts_with($name, 'rates') => 'rates',
            str_starts_with($name, 'settings') => 'settings',
            str_starts_with($name, 'api-settings') => 'api-settings',
            default => null,
        };
    }

    /**
     * Map notification types to the sections an admin must have to see them.
     * Returns the allowed notification types for this admin.
     * Super admins return all types effectively (checked externally via isSuperAdmin).
     *
     * @return array<int, string>
     */
    public function allowedNotificationTypes(): array
    {
        $types = [];

        if ($this->canAccessSection('customers')) {
            $types[] = 'customer';
        }
        if ($this->canAccessSection('orders')) {
            array_push($types, 'order', 'fulfillment');
        }
        if ($this->canAccessSection('transactions') || $this->canAccessSection('wallets')) {
            array_push($types, 'payment', 'wallet', 'transaction');
        }
        if ($this->canAccessSection('support-tickets')) {
            array_push($types, 'ticket', 'support-ticket');
        }

        // Let basic operational alerts through if they have Operations access
        if ($this->canAccessAdminRoute('operations')) {
            $types[] = 'system';
        }

        return $types;
    }

    /**
     * Check if this admin account is active.
     */
    public function isActive(): bool
    {
        return $this->is_active === true;
    }

    /**
     * Get the admin's initials for avatar fallback.
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->map(fn (string $name) => Str::of($name)->substr(0, 1))
            ->implode('');
    }

    /**
     * A deterministic SVG initials avatar (base64 data URI) for use when the
     * admin hasn't uploaded a photo. Mirrors {@see User::initialsAvatar()}.
     */
    public function initialsAvatar(int $size = 128): string
    {
        $initials = Str::of($this->initials())->upper()->substr(0, 2)->value() ?: 'U';
        $initials = htmlspecialchars($initials, ENT_QUOTES);

        $palette = ['#2563eb', '#7c3aed', '#0d9488', '#dc2626', '#ea580c', '#0891b2', '#4f46e5', '#db2777', '#16a34a', '#d97706'];
        $bg = $palette[crc32((string) ($this->name ?: $this->email ?: 'admin')) % count($palette)];
        $fontSize = (int) round($size * 0.42);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$size.'" height="'.$size.'" viewBox="0 0 '.$size.' '.$size.'">'
            .'<rect width="'.$size.'" height="'.$size.'" fill="'.$bg.'"/>'
            .'<text x="50%" y="50%" dy=".05em" fill="#ffffff" font-family="system-ui,-apple-system,Segoe UI,Roboto,sans-serif" font-size="'.$fontSize.'" font-weight="600" text-anchor="middle" dominant-baseline="central">'.$initials.'</text>'
            .'</svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * Record a login timestamp.
     */
    public function recordLogin(): void
    {
        $this->update(['last_login_at' => now()]);
    }

    /**
     * Determine if the admin has TOTP 2FA enabled and confirmed.
     */
    public function hasTotpEnabled(): bool
    {
        return ! is_null($this->two_factor_secret) && ! is_null($this->two_factor_confirmed_at);
    }

    /**
     * Get the admin's push subscriptions.
     */
    public function pushSubscriptions()
    {
        return $this->morphMany(PushSubscription::class, 'subscribable');
    }
}
