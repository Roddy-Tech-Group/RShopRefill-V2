<?php

namespace App\Http\View\Composers;

use App\Models\SiteSetting;
use Illuminate\View\View;

/**
 * Shares the public-facing brand identity with every view so the website name
 * is sourced from one admin-editable setting (System Settings -> Site) instead
 * of being hardcoded across the layouts, nav, footer and emails.
 *
 * Bound to '*', so `$siteName` is available in storefront, dashboard, admin,
 * auth and mail views alike. The read goes through SiteSetting::get, which is
 * already cache-backed and busted by SiteSetting::put - so no extra memo here:
 * a per-process cache would otherwise serve a stale name to long-running queue
 * workers until they restarted.
 */
class SiteIdentityComposer
{
    public function compose(View $view): void
    {
        $view->with('siteName', $this->siteName());
    }

    /**
     * Resolve the brand name, but never let a settings lookup break a view
     * render: if the table or DB is unavailable (early boot, an unmigrated
     * test that only renders markup, a DB outage serving an error page) fall
     * back to the literal brand.
     */
    private function siteName(): string
    {
        try {
            return (string) SiteSetting::get('site.name', 'RshopRefills');
        } catch (\Throwable $e) {
            return 'RshopRefills';
        }
    }
}
