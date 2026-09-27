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
 * auth and mail views alike - which also means it runs for EVERY view
 * rendered: a listing page renders ~1,800 of them. The name is therefore
 * resolved once per request and memoized on the request instance; on the
 * database cache driver the unmemoized read was ~1,800 cache-table queries
 * per page. The memo key carries a 60-second time bucket so a long-running
 * queue worker (whose base request object lives for the process) picks up a
 * renamed brand within a minute rather than at restart - the staleness that
 * kept this composer memo-free before.
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
        $memoKey = 'memo:site_identity:name:'.intdiv(time(), 60);
        $attributes = request()->attributes;

        if (! $attributes->has($memoKey)) {
            try {
                $attributes->set($memoKey, (string) SiteSetting::get('site.name', 'RshopRefill'));
            } catch (\Throwable $e) {
                return 'RshopRefill';
            }
        }

        return $attributes->get($memoKey);
    }
}
