{{--
    Admin dashboard shell - /admin/dashboard.

    Thin shell: this view owns the admin layout + the page-level <style>
    (KPI dark-mode text contrast, inset table dividers). The main content
    is the lazy <livewire:admin.dashboard-content> component which returns
    the admin-dashboard skeleton placeholder instantly, then boots and runs
    the heavy DashboardMetricsQuery aggregations.

    Same pattern as the customer dashboard's <livewire:dashboard.overview lazy />.
--}}
<x-layouts.admin>

    {{-- Dark-mode text contrast on the KPI cards. The project's app.css remap of
         `.dark .text-zinc-600` was sitting at a too-dim grey and Tailwind's dark
         variants couldn't override it (custom dark variant uses :where() which
         zeros specificity). Inline this rule so it ships with the HTML and beats
         the cascade regardless of CSS pipeline state. --}}
    <style>
        html.dark .kpi-card .kpi-label { color: #f4f4f5 !important; }
        html.dark .kpi-card .kpi-sub   { color: #d4d4d8 !important; }
    </style>

    {{-- Lazy body: shows components.skeletons.admin-dashboard until the
         metrics query finishes, then swaps in the real KPI cards, map,
         trends chart, and Latest Users / Latest Transactions tables. --}}
    <livewire:admin.operations-dashboard-content lazy />

</x-layouts.admin>
