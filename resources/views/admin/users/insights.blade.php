@php
    /** @var array $insights AudienceInsightsService::build() output */
    $service = app(\App\Domain\Admin\Services\AudienceInsightsService::class);

    $topUsers = $insights['users_by_country']->take(10);
    $topOrders = $insights['orders_by_country']->take(10);
@endphp

<x-layouts.admin>
    <x-slot:heading>
        <div class="flex items-end justify-between gap-3">
            <span>Audience Insights</span>
            <div class="flex items-center gap-2">
                {{-- Segmented window control, same chip pattern as /admin/reports. --}}
                <div class="inline-flex items-center gap-0.5 rounded-[8px] bg-zinc-100 p-0.5 dark:bg-[#26416b]">
                    @foreach ([30 => '30 days', 90 => '90 days', null => 'All time'] as $value => $label)
                        @php $active = $window === $value; @endphp
                        <a href="{{ route('admin.users.insights', $value ? ['window' => $value] : []) }}" @class([
                            'rounded-[8px] px-4 py-0.5 text-[12px] font-semibold transition-colors',
                            'bg-blue-600 text-white shadow-sm' => $active,
                            'text-zinc-600 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white' => ! $active,
                        ])>{{ $label }}</a>
                    @endforeach
                </div>
                <a href="{{ route('admin.users.insights.export', $window ? ['window' => $window] : []) }}"
                   class="inline-flex items-center gap-1.5 rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-xs font-semibold text-zinc-700 transition-colors hover:bg-zinc-50 dark:border-zinc-700/60 dark:bg-[#1d3252] dark:text-zinc-300 dark:hover:bg-[#26416b]">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/>
                    </svg>
                    Export CSV
                </a>
            </div>
        </div>
    </x-slot:heading>

    <div class="space-y-6">
        <div class="grid gap-6 lg:grid-cols-2">
            <div class="rounded-[12px] bg-white p-5 shadow-sm shadow-zinc-900/[0.04] dark:bg-[#1d3252]">
                <h2 class="text-sm font-bold text-zinc-900 dark:text-white">Users by country</h2>
                <p class="mb-3 text-xs text-zinc-500 dark:text-zinc-400">Top 10 · {{ number_format($insights['total_users']) }} users in this window</p>
                <div wire:key="um-users-{{ $window ?? 'all' }}" wire:ignore x-data="umBarChart({
                    labels: @js($topUsers->map(fn ($r) => $service->label($r->country))->all()),
                    values: @js($topUsers->pluck('users')->all()),
                    name: 'Users',
                    height: 320,
                })" x-on:livewire:navigating.window="destroy()">
                    <div x-ref="canvas" class="min-h-[320px]"></div>
                </div>
            </div>

            <div class="rounded-[12px] bg-white p-5 shadow-sm shadow-zinc-900/[0.04] dark:bg-[#1d3252]">
                <h2 class="text-sm font-bold text-zinc-900 dark:text-white">Revenue by country</h2>
                <p class="mb-3 text-xs text-zinc-500 dark:text-zinc-400">Top 10 · ${{ number_format($insights['total_revenue_usd'], 2) }} across {{ number_format($insights['total_orders']) }} completed orders</p>
                <div wire:key="um-revenue-{{ $window ?? 'all' }}" wire:ignore x-data="umBarChart({
                    labels: @js($topOrders->map(fn ($r) => $service->label($r->country))->all()),
                    values: @js($topOrders->pluck('revenue_usd')->all()),
                    name: 'Revenue',
                    prefix: '$',
                    height: 320,
                })" x-on:livewire:navigating.window="destroy()">
                    <div x-ref="canvas" class="min-h-[320px]"></div>
                </div>
            </div>
        </div>

        <div class="rounded-[12px] bg-white p-5 shadow-sm shadow-zinc-900/[0.04] dark:bg-[#1d3252]">
            <h2 class="text-sm font-bold text-zinc-900 dark:text-white">Weekly signups</h2>
            <p class="mb-3 text-xs text-zinc-500 dark:text-zinc-400">Last 12 weeks · top 5 countries, everyone else as Rest</p>
            <div wire:ignore x-data="umAreaChart(@js($insights['weekly_signups']))" x-on:livewire:navigating.window="destroy()">
                <div x-ref="canvas" class="min-h-[320px]"></div>
            </div>
        </div>

        {{-- The table view: every country, exportable, and the accessible twin
             of the charts above. --}}
        <div class="overflow-hidden rounded-[12px] bg-white shadow-sm shadow-zinc-900/[0.04] dark:bg-[#1d3252]">
            <div class="overflow-x-auto p-3">
                <table class="w-full text-sm admin-table">
                    <thead>
                        <tr class="text-left">
                            <th class="">Country</th>
                            <th class="text-right">Users</th>
                            <th class="text-right">Orders</th>
                            <th class="text-right">Revenue (USD)</th>
                            <th class="text-right">Avg order (USD)</th>
                            <th class="text-right">Verified</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($insights['table'] as $row)
                            <tr class="border-b border-zinc-50 last:border-0 dark:border-white/5">
                                <td class="px-5 py-3 font-medium text-zinc-900 dark:text-white">{{ $row['label'] }}</td>
                                <td class="px-5 py-3 text-right tabular-nums text-zinc-700 dark:text-zinc-200">{{ number_format($row['users']) }}</td>
                                <td class="px-5 py-3 text-right tabular-nums text-zinc-700 dark:text-zinc-200">{{ number_format($row['orders']) }}</td>
                                <td class="px-5 py-3 text-right tabular-nums text-zinc-700 dark:text-zinc-200">${{ number_format($row['revenue_usd'], 2) }}</td>
                                <td class="px-5 py-3 text-right tabular-nums text-zinc-700 dark:text-zinc-200">${{ number_format($row['aov_usd'], 2) }}</td>
                                <td class="px-5 py-3 text-right tabular-nums text-zinc-700 dark:text-zinc-200">{{ $row['verified_pct'] }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-layouts.admin>
