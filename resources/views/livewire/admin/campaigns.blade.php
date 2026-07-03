<?php

use Livewire\Volt\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use App\Models\NotificationCampaign;

new
#[Layout('components.layouts.admin')]
#[Title('Push Campaigns')]
class extends Component {
    use WithPagination;

    public string $search = '';
    public string $status = '';

    public function with(): array
    {
        $campaigns = NotificationCampaign::query()
            ->when($this->search, function ($query) {
                $query->where('title', 'like', '%' . $this->search . '%');
            })
            ->when($this->status, function ($query) {
                $query->where('status', $this->status);
            })
            ->latest()
            ->paginate(10);

        return [
            'campaigns' => $campaigns,
        ];
    }
};
?>

<div>
    <x-slot:heading>Push Campaigns</x-slot:heading>
    <x-slot:subheading>Create and manage broadcast notifications to engage your audience.</x-slot:subheading>

    <div class="flex flex-col gap-6">
        <div class="flex items-center justify-end">
            <button
                wire:navigate
                href="{{ route('admin.campaign-editor') }}"
                class="inline-flex items-center gap-1.5 rounded-[12px] bg-blue-600 px-3 py-2 text-xs font-semibold text-white transition-colors hover:bg-blue-700"
            >
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14"/>
                </svg>
                New Campaign
            </button>
        </div>

        {{-- Metrics Overview --}}
        <div class="mb-8 grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
            @php
                $metrics = [
                    ['label' => 'Total Campaigns', 'value' => \App\Models\NotificationCampaign::count(), 'trend' => '+12%'],
                    ['label' => 'Messages Sent', 'value' => \App\Models\NotificationCampaign::sum('stats_sent') ?? 0, 'trend' => '+2.4k'],
                    ['label' => 'Avg. Click Rate', 'value' => '12.4%', 'trend' => '+1.1%'],
                    ['label' => 'Active Subscriptions', 'value' => \App\Models\PushSubscription::count(), 'trend' => '+14'],
                ];
            @endphp
            
            @foreach($metrics as $metric)
                <div class="flex flex-col justify-between rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/5">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ $metric['label'] }}</span>
                        <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">
                            {{ $metric['trend'] }}
                        </span>
                    </div>
                    <div class="mt-4 text-3xl font-bold tracking-tight text-zinc-900 dark:text-white">
                        {{ number_format((float) $metric['value']) }}{{ str_contains((string)$metric['value'], '%') ? '%' : '' }}
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Filters & Search --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="w-full sm:max-w-xs">
                <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Search campaigns..." />
            </div>
            
            <div class="flex items-center gap-3">
                <flux:select wire:model.live="status" class="w-40">
                    <option value="">All Statuses</option>
                    <option value="draft">Drafts</option>
                    <option value="scheduled">Scheduled</option>
                    <option value="sent">Sent</option>
                </flux:select>
            </div>
        </div>

        {{-- Campaigns Table --}}
        <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-white/10 dark:bg-zinc-900/50">
            <table class="admin-table w-full text-left text-sm">
                <thead>
                    <tr>
                        <th class="px-4 py-3 font-medium text-zinc-500">Campaign</th>
                        <th class="px-4 py-3 font-medium text-zinc-500">Status</th>
                        <th class="px-4 py-3 font-medium text-zinc-500">Audience</th>
                        <th class="px-4 py-3 font-medium text-zinc-500">Performance</th>
                        <th class="px-4 py-3 font-medium text-zinc-500">Date</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-zinc-200 dark:divide-white/10">
                    @forelse ($campaigns as $campaign)
                        <tr class="hover:bg-zinc-50 dark:hover:bg-white/5">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-500/10 dark:text-blue-400">
                                        <flux:icon.megaphone class="size-5" />
                                    </div>
                                    <div>
                                        <div class="font-medium text-zinc-900 dark:text-white">
                                            {{ $campaign->title }}
                                        </div>
                                        <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                            {{ ucfirst($campaign->category) }}
                                        </div>
                                    </div>
                                </div>
                            </td>

                            <td class="px-4 py-3">
                                @if($campaign->status === 'sent')
                                    <flux:badge color="success" size="sm" class="uppercase">Sent</flux:badge>
                                @elseif($campaign->status === 'scheduled')
                                    <flux:badge color="warning" size="sm" class="uppercase">Scheduled</flux:badge>
                                @else
                                    <flux:badge color="zinc" size="sm" class="uppercase">Draft</flux:badge>
                                @endif
                            </td>

                            <td class="px-4 py-3">
                                <div class="text-sm text-zinc-900 dark:text-white">
                                    @if(empty($campaign->audience_filters))
                                        All Users
                                    @else
                                        Segmented ({{ count($campaign->audience_filters) }} filters)
                                    @endif
                                </div>
                            </td>

                            <td class="px-4 py-3">
                                @if($campaign->status === 'sent')
                                    <div class="flex flex-col gap-1 text-xs">
                                        <div class="flex items-center gap-2">
                                            <span class="text-zinc-500 w-12">Sent:</span>
                                            <span class="font-medium text-zinc-900 dark:text-white">{{ number_format($campaign->stats_sent) }}</span>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <span class="text-zinc-500 w-12">Clicked:</span>
                                            <span class="font-medium text-blue-600 dark:text-blue-400">{{ number_format($campaign->stats_clicked) }}</span>
                                        </div>
                                    </div>
                                @else
                                    <span class="text-sm text-zinc-500">—</span>
                                @endif
                            </td>

                            <td class="px-4 py-3">
                                <div class="text-sm text-zinc-900 dark:text-white">
                                    {{ $campaign->scheduled_at ? $campaign->scheduled_at->format('M j, Y g:i A') : $campaign->created_at->format('M j, Y') }}
                                </div>
                            </td>

                            <td class="px-4 py-3 text-right">
                                <flux:dropdown>
                                    <flux:button variant="ghost" icon="ellipsis-horizontal" size="sm" />
                                    
                                    <flux:navmenu>
                                        <flux:navmenu.item href="{{ route('admin.campaign-editor.edit', $campaign->id) }}" wire:navigate icon="pencil">Edit</flux:navmenu.item>
                                        @if($campaign->status === 'draft')
                                            <flux:navmenu.item wire:click="deleteCampaign({{ $campaign->id }})" icon="trash" variant="danger">Delete</flux:navmenu.item>
                                        @endif
                                    </flux:navmenu>
                                </flux:dropdown>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-12 text-center">
                                <div class="flex flex-col items-center justify-center">
                                    <div class="flex h-12 w-12 items-center justify-center rounded-full bg-blue-50 text-blue-600 dark:bg-blue-500/10 dark:text-blue-400">
                                        <flux:icon.megaphone class="size-6" />
                                    </div>
                                    <h3 class="mt-4 text-sm font-semibold text-zinc-900 dark:text-white">No campaigns found</h3>
                                    <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">Get started by creating your first broadcast campaign.</p>
                                    <div class="mt-6">
                                        <flux:button href="{{ route('admin.campaign-editor') }}" wire:navigate variant="primary" icon="plus">
                                            Create Campaign
                                        </flux:button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            @if($campaigns->hasPages())
                <div class="border-t border-zinc-200 px-4 py-3 dark:border-white/10">
                    {{ $campaigns->links() }}
                </div>
            @endif
        </div>
    </div>
</div>
