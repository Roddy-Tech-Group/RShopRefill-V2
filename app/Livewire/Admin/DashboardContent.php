<?php

namespace App\Livewire\Admin;

use App\Domain\Admin\Queries\DashboardMetricsQuery;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * Lazy wrapper for the admin dashboard body.
 *
 * The shell view (admin/dashboard.blade.php) renders the layout chrome + a
 * skeleton placeholder instantly; this component then lazy-boots, runs the
 * heavy DashboardMetricsQuery aggregations in the body view, and swaps the
 * real dashboard in.
 *
 * Implemented as a regular Livewire class (not a Volt single-file component)
 * because Volt's anonymous-class compiler choked on the body's nested @php
 * blocks + closures during the lazy hydration POST.
 */
#[Lazy]
class DashboardContent extends Component
{
    // KPI date-range filter (?range=today|7d|30d|90d|year|custom + ?start=&?end=)
    public ?string $rangePreset = null;

    public ?string $rangeStart = null;

    public ?string $rangeEnd = null;

    // Trends chart (Sales / Cost over N days). Defaults to the week so the
    // dashboard opens on recent activity rather than a flat month.
    public int $revenueDays = 7;

    // Best-Selling-Countries map
    public int $countryDays = 7;

    public string $countryCategory = 'all';

    /**
     * Hydrate the filter state from the request query string on first mount.
     * Lets a user share a deep-link like ?revenue_days=90 and land on the
     * dashboard already filtered. After mount, every filter click runs as a
     * Livewire action against these properties (no SPA navigation), so the
     * lazy placeholder skeleton fires once on the initial load and never
     * again on subsequent filter interactions.
     */
    public function mount(): void
    {
        $this->rangePreset = request()->queryString('range') ?: null;
        $this->rangeStart = request()->queryString('start') ?: null;
        $this->rangeEnd = request()->queryString('end') ?: null;
        $this->revenueDays = max(1, min(365, (int) request('revenue_days', 7)));
        $this->countryDays = max(1, min(365, (int) request('country_days', 7)));
        $this->countryCategory = request()->queryString('country_cat', 'all');
    }

    /** Trends chart period dropdown. */
    public function setRevenueDays(int $days): void
    {
        $this->revenueDays = max(1, min(365, $days));

        // The chart canvas is wire:ignore (Livewire would wipe the Apex SVG on
        // re-render), so the fresh series travels via a browser event and the
        // salesCostChart factory swaps it into the live chart in place.
        $this->dispatch('trends-data-updated',
            series: app(DashboardMetricsQuery::class)->getSalesCostTimeseries($this->revenueDays),
        );
    }

    /** Best Selling Countries period dropdown. */
    public function setCountryDays(int $days): void
    {
        $this->countryDays = max(1, min(365, $days));
        $this->dispatchMapData();
    }

    /** Best Selling Countries product category dropdown. */
    public function setCountryCategory(string $key): void
    {
        $this->countryCategory = in_array($key, ['all', 'esim', 'gift', 'topup'], true)
            ? $key
            : 'all';
        $this->dispatchMapData();
    }

    /**
     * Push the freshly-aggregated map data to the Alpine factory via a browser
     * event. The map canvas itself is wrapped in wire:ignore (jsvectormap
     * injects raw SVG that Livewire would otherwise wipe on every component
     * re-render), so we feed it new aggregates via a side-channel and the
     * factory's updateData() method re-shades in place.
     */
    private function dispatchMapData(): void
    {
        $query = app(DashboardMetricsQuery::class);
        $countriesByCode = $query->getBestSellingCountries($this->countryDays, $this->countryCategory);
        $codeToContinent = (array) config('continents.codes', []);

        $salesByRegion = [];
        foreach ($countriesByCode as $cc => $total) {
            $region = $codeToContinent[$cc] ?? null;
            if ($region === null) {
                continue;
            }
            $salesByRegion[$region] = ($salesByRegion[$region] ?? 0) + (float) $total;
        }

        // dispatch() with a browser-event name (no namespace prefix needed)
        // emits a window CustomEvent the Alpine x-on listener catches.
        $this->dispatch('map-data-updated',
            countries: $countriesByCode,
            regions: $salesByRegion,
        );
    }

    public function placeholder()
    {
        return view('components.skeletons.admin-dashboard');
    }

    public function render()
    {
        return view('livewire.admin.dashboard-content');
    }
}
