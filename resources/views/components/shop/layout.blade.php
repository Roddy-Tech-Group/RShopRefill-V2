@props([
    'title' => null,
    'description' => null,
    'ogImage' => null,
    'ogType' => null,
    'keywords' => null,
    'jsonLd' => null,
])

@php
    // Auto-detects context: URLs under /dashboard/shop/* AND the native eSIM
    // top-up under /dashboard/esims/* render in the dashboard chrome (sidebar,
    // dashboard header, mobile bottom nav + sticky back button). Everywhere else
    // falls back to the public storefront chrome. Same body, two presentations -
    // so the catalog views below stay layout-agnostic.
    $inDashboard = auth()->check()
        && (request()->is('dashboard/shop*') || request()->is('dashboard/esims/*'));
@endphp

@if ($inDashboard)
    <x-layouts.dashboard>
        {{ $slot }}
    </x-layouts.dashboard>
@else
    <x-layouts.app.header :title="$title" :description="$description" :og-image="$ogImage" :og-type="$ogType" :keywords="$keywords" :json-ld="$jsonLd">
        {{ $slot }}
    </x-layouts.app.header>
@endif
