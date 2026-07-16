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

        /* Inset table dividers - the horizontal hairline between rows stops
           1.25rem short of the card's left and right edges instead of
           running corner-to-corner. Painted as a background gradient on the
           top of every non-first row so it's reliable across all browsers
           where pseudo-elements on <tr>/<td> behave inconsistently. */
        .inset-divide tbody > tr + tr > td {
            background-image: linear-gradient(
                to right,
                transparent 0,
                transparent 1.25rem,
                #f4f4f5 1.25rem,
                #f4f4f5 calc(100% - 1.25rem),
                transparent calc(100% - 1.25rem),
                transparent 100%
            );
            background-size: 100% 1px;
            background-position: top left;
            background-repeat: no-repeat;
        }
        html.dark .inset-divide tbody > tr + tr > td {
            background-image: linear-gradient(
                to right,
                transparent 0,
                transparent 1.25rem,
                rgba(255, 255, 255, 0.08) 1.25rem,
                rgba(255, 255, 255, 0.08) calc(100% - 1.25rem),
                transparent calc(100% - 1.25rem),
                transparent 100%
            );
        }
    </style>

    {{-- Lazy body: shows components.skeletons.admin-dashboard until the
         metrics query finishes, then swaps in the real KPI cards, map,
         trends chart, and Latest Users / Latest Transactions tables. --}}
    <livewire:admin.operations-dashboard-content lazy />

</x-layouts.admin>
