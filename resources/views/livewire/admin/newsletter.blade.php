{{-- FILE: resources/views/livewire/admin/newsletter.blade.php --}}
<?php

use App\Jobs\SendNewsletterBroadcastJob;
use App\Mail\NewsletterBroadcastMail;
use App\Models\NewsletterSubscriber;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new
#[Layout('components.layouts.admin')]
#[Title('Newsletter')]
class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(as: 'status', except: 'all')]
    public string $statusFilter = 'all';

    // ── Compose campaign form state ───────────────────────────────────
    public bool $showCompose = false;

    #[Validate('required|string|max:150')]
    public string $subject = '';

    #[Validate('required|string|max:50000')]
    public string $body = '';

    /** When true the body is sent as raw HTML; otherwise plain text with
     *  blank-line paragraph splitting. */
    public bool $isHtml = false;

    /** Optional test address — when set, "Send test" routes the email
     *  there instead of the live subscriber list. */
    #[Validate('nullable|email|max:150')]
    public string $testEmail = '';

    #[Computed]
    public function counts(): array
    {
        return [
            'total'        => NewsletterSubscriber::count(),
            'active'       => NewsletterSubscriber::where('status', 'active')->count(),
            'unsubscribed' => NewsletterSubscriber::where('status', 'unsubscribed')->count(),
        ];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function unsubscribe(int $id): void
    {
        NewsletterSubscriber::findOrFail($id)->update([
            'status'            => 'unsubscribed',
            'unsubscribed_at'   => now(),
        ]);
        session()->flash('status', 'Subscriber removed from list.');
        unset($this->counts);
    }

    public function resubscribe(int $id): void
    {
        NewsletterSubscriber::findOrFail($id)->update([
            'status'            => 'active',
            'unsubscribed_at'   => null,
        ]);
        session()->flash('status', 'Subscriber re-activated.');
        unset($this->counts);
    }

    public function openCompose(): void
    {
        $this->reset(['subject', 'body', 'isHtml', 'testEmail']);
        $this->resetValidation();
        $this->showCompose = true;
    }

    public function closeCompose(): void
    {
        $this->showCompose = false;
    }

    /**
     * Send a one-off test of the composed campaign to the address typed
     * into $testEmail. Doesn't touch the subscriber list — useful for
     * eyeballing how the rendered email will land before pressing the
     * real broadcast button.
     */
    public function sendTest(): void
    {
        $this->validate([
            'subject'   => 'required|string|max:150',
            'body'      => 'required|string|max:50000',
            'testEmail' => 'required|email|max:150',
        ]);

        try {
            Mail::to(trim($this->testEmail))->send(new NewsletterBroadcastMail(
                subjectLine: $this->subject,
                bodyContent: $this->body,
                isHtml: $this->isHtml,
            ));
            session()->flash('status', 'Test email sent to '.$this->testEmail);
        } catch (\Throwable $e) {
            session()->flash('error', 'Test send failed: '.$e->getMessage());
        }
    }

    /**
     * Queue the broadcast to every active subscriber. The job iterates
     * subscribers in chunks of 50 and queues a dedicated send per address,
     * so a single bounce doesn't poison the rest of the batch.
     */
    /**
     * Stream the current subscriber list as a Resend-compatible CSV (columns
     * email, first_name, last_name, unsubscribed). Respects the active status
     * filter so admins can export only the slice they want — e.g. "active" for
     * a new Resend audience, "unsubscribed" for a suppression list, "all" for
     * a full backup. The file is streamed (no temp file, no memory blow-up
     * even with large lists).
     */
    public function exportCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $filter = $this->statusFilter;
        $search = trim($this->search);

        $filename = 'rshoprefills-subscribers-'
            .($filter === 'all' ? 'all' : $filter)
            .'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($filter, $search) {
            $out = fopen('php://output', 'w');

            // Resend audience header row. Leave first_name/last_name blank
            // since we don't collect names today; Resend treats blanks as OK.
            fputcsv($out, ['email', 'first_name', 'last_name', 'unsubscribed']);

            NewsletterSubscriber::query()
                ->when($filter !== 'all', fn ($q) => $q->where('status', $filter))
                ->when($search !== '', fn ($q) => $q->where('email', 'like', '%'.$search.'%'))
                ->orderBy('email')
                ->chunkById(500, function ($rows) use ($out) {
                    foreach ($rows as $row) {
                        fputcsv($out, [
                            $row->email,
                            '',
                            '',
                            $row->status === 'unsubscribed' ? 'true' : 'false',
                        ]);
                    }
                });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function broadcast(): void
    {
        $this->validate([
            'subject' => 'required|string|max:150',
            'body'    => 'required|string|max:50000',
        ]);

        $recipients = NewsletterSubscriber::where('status', 'active')->count();
        if ($recipients === 0) {
            session()->flash('error', 'No active subscribers to send to.');
            return;
        }

        SendNewsletterBroadcastJob::dispatch(
            subjectLine: $this->subject,
            bodyContent: $this->body,
            isHtml: $this->isHtml,
        );

        session()->flash('status', 'Broadcast queued. Sending to '.number_format($recipients).' active subscribers in the background.');
        $this->closeCompose();
    }

    public function with(): array
    {
        $query = NewsletterSubscriber::query()->latest('subscribed_at');

        if ($this->statusFilter !== 'all') {
            $query->where('status', $this->statusFilter);
        }

        if ($this->search !== '') {
            $query->where('email', 'like', '%' . trim($this->search) . '%');
        }

        return [
            'subscribers' => $query->paginate(25),
        ];
    }
}; ?>

<div>
    <x-slot:heading>Newsletter</x-slot:heading>
    <x-slot:subheading>Manage email subscribers and broadcast campaigns to your list.</x-slot:subheading>

    <div class="flex flex-col gap-6">

        @if (session('status'))
            <div class="rounded-[12px] bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-500/15 dark:text-emerald-300 dark:ring-emerald-500/30">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="rounded-[12px] bg-red-50 px-4 py-3 text-sm font-medium text-red-700 ring-1 ring-red-200 dark:bg-red-500/15 dark:text-red-300 dark:ring-red-500/30">{{ session('error') }}</div>
        @endif

        {{-- Compose button — primary action above the KPI strip. --}}
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                <span class="font-semibold text-zinc-900 dark:text-white">{{ number_format($this->counts['active']) }}</span> active subscribers ready to receive your next campaign.
            </p>
            <div class="flex flex-wrap items-center gap-2">
                {{-- Export CSV — Resend-compatible columns (email, first_name,
                     last_name, unsubscribed) so admins can upload directly to a
                     Resend audience for auto marketing emails. Respects the
                     active status filter + search. --}}
                <button
                    wire:click="exportCsv"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-[12px] bg-white px-4 py-2 text-sm font-semibold text-blue-700 ring-1 ring-blue-200 transition-colors hover:bg-blue-50 dark:bg-[#1d3252] dark:text-blue-300 dark:ring-blue-500/30 dark:hover:bg-[#26416b]"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/>
                    </svg>
                    Export CSV
                </button>
                <button
                    wire:click="openCompose"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-[12px] bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-blue-700"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75"/>
                    </svg>
                    Compose newsletter
                </button>
            </div>
        </div>

        {{-- KPI strip --}}
        <div class="grid grid-cols-3 gap-3">
            @foreach ([
                ['label' => 'Total',        'value' => $this->counts['total'],        'dot' => 'bg-blue-500'],
                ['label' => 'Active',        'value' => $this->counts['active'],       'dot' => 'bg-emerald-500'],
                ['label' => 'Unsubscribed', 'value' => $this->counts['unsubscribed'], 'dot' => 'bg-zinc-400'],
            ] as $stat)
                <div class="rounded-[12px] border-[1.5px] border-white bg-white p-4 shadow-sm shadow-zinc-900/[0.04] dark:border-white dark:bg-[#1d3252]">
                    <p class="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-600 dark:text-zinc-300">
                        <span class="inline-block h-1.5 w-1.5 rounded-full {{ $stat['dot'] }}"></span>
                        {{ $stat['label'] }}
                    </p>
                    <p class="mt-2 text-3xl font-bold tracking-tight text-zinc-900 dark:text-white">{{ number_format($stat['value']) }}</p>
                </div>
            @endforeach
        </div>

        {{-- Search + filter row --}}
        <div class="flex flex-col items-stretch gap-3 sm:flex-row sm:items-center">
            <div class="relative flex-1">
                <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-500 dark:text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <input
                    wire:model.live.debounce.250ms="search"
                    type="search"
                    placeholder="Search by email address..."
                    class="w-full rounded-[12px] border border-zinc-200 bg-white py-2.5 pl-10 pr-3 text-sm text-zinc-900 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#1d3252] dark:text-white"
                />
            </div>

            <div class="flex flex-wrap items-center gap-1.5">
                @foreach (['all' => 'All', 'active' => 'Active', 'unsubscribed' => 'Unsubscribed'] as $value => $label)
                    <button
                        type="button"
                        wire:click="$set('statusFilter', '{{ $value }}')"
                        @class([
                            'rounded-[12px] px-3 py-1.5 text-xs font-semibold transition-colors',
                            'bg-blue-600 text-white' => $statusFilter === $value,
                            'bg-white text-zinc-700 ring-1 ring-zinc-200 hover:bg-zinc-50 dark:bg-[#1d3252] dark:text-zinc-300 dark:ring-zinc-700/60 dark:hover:bg-[#26416b]' => $statusFilter !== $value,
                        ])
                    >{{ $label }}</button>
                @endforeach
            </div>
        </div>

        {{-- Table --}}
        <div class="overflow-hidden rounded-[12px] border-[1.5px] border-white bg-white shadow-sm shadow-zinc-900/[0.04] dark:border-white dark:bg-[#1d3252]">
            <div class="overflow-x-auto p-3">
                <table class="admin-table w-full text-left text-sm" style="border-spacing: 0 6px;">
                    <thead>
                        <tr>
                            <th>Email</th>
                            <th class="hidden sm:table-cell">Source</th>
                            <th>Status</th>
                            <th class="hidden md:table-cell">Subscribed</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($subscribers as $sub)
                            <tr>
                                <td class="font-medium text-zinc-900 dark:text-white">{{ $sub->email }}</td>
                                <td class="hidden sm:table-cell">
                                    @if ($sub->source)
                                        <x-admin.badge tone="blue">{{ $sub->source }}</x-admin.badge>
                                    @else
                                        <span class="text-xs text-zinc-400 dark:text-zinc-600">-</span>
                                    @endif
                                </td>
                                <td>
                                    <x-admin.badge :tone="$sub->status === 'active' ? 'emerald' : 'zinc'">
                                        {{ $sub->status }}
                                    </x-admin.badge>
                                </td>
                                <td class="hidden whitespace-nowrap text-xs text-zinc-500 dark:text-zinc-400 md:table-cell">
                                    {{ $sub->subscribed_at->format('M j, Y') }}
                                </td>
                                <td class="whitespace-nowrap text-right">
                                    @if ($sub->status === 'active')
                                        <button
                                            wire:click="unsubscribe({{ $sub->id }})"
                                            wire:confirm="Remove this subscriber from the list?"
                                            type="button"
                                            class="rounded-[5px] bg-red-50 px-2.5 py-1 text-[11px] font-semibold text-red-700 transition-colors hover:bg-red-100 dark:bg-red-500/15 dark:text-red-300 dark:hover:bg-red-500/25"
                                        >Unsubscribe</button>
                                    @else
                                        <button
                                            wire:click="resubscribe({{ $sub->id }})"
                                            type="button"
                                            class="rounded-[5px] bg-emerald-50 px-2.5 py-1 text-[11px] font-semibold text-emerald-700 transition-colors hover:bg-emerald-100 dark:bg-emerald-500/15 dark:text-emerald-300 dark:hover:bg-emerald-500/25"
                                        >Re-subscribe</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-16 text-center">
                                    <p class="text-base font-semibold text-zinc-900 dark:text-white">No subscribers match those filters</p>
                                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Try adjusting the search or status filter.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($subscribers->hasPages())
                <div class="border-t border-zinc-100 px-5 py-3 dark:border-zinc-700/60">
                    {{ $subscribers->links() }}
                </div>
            @endif
        </div>
    </div>

    {{-- Compose campaign modal --}}
    @if ($showCompose)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true">
            <div wire:click="closeCompose" class="absolute inset-0 bg-zinc-900/40"></div>
            <form wire:submit="broadcast" class="relative flex max-h-[92vh] w-full max-w-3xl flex-col overflow-hidden rounded-[12px] bg-white shadow-2xl dark:bg-[#1d3252]">
                <div class="flex shrink-0 items-start justify-between gap-4 border-b border-zinc-100 px-5 py-4 dark:border-zinc-700/60">
                    <div>
                        <h3 class="text-sm font-bold text-zinc-900 dark:text-white">Compose newsletter</h3>
                        <p class="mt-0.5 text-[11px] text-zinc-500 dark:text-zinc-400">Broadcasts to <strong>{{ number_format($this->counts['active']) }}</strong> active subscriber{{ $this->counts['active'] === 1 ? '' : 's' }}.</p>
                    </div>
                    <button type="button" wire:click="closeCompose" aria-label="Close" class="flex h-8 w-8 items-center justify-center rounded-[12px] bg-zinc-100 text-zinc-600 transition-colors hover:bg-zinc-200 dark:bg-[#26416b] dark:text-zinc-300 dark:hover:bg-[#34507a]">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <div class="space-y-4 overflow-y-auto px-5 py-4">

                    {{-- Subject --}}
                    <div>
                        <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Subject</label>
                        <input
                            wire:model="subject"
                            type="text"
                            placeholder="e.g. February updates from {{ $siteName }}"
                            class="mt-1.5 w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#0c1a36] dark:text-white"
                        >
                        @error('subject') <p class="mt-1 text-[11px] font-medium text-red-600">{{ $message }}</p> @enderror
                    </div>

                    {{-- Format toggle: plain text vs HTML --}}
                    <div>
                        <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Format</label>
                        <div class="mt-1.5 inline-flex items-center rounded-[12px] bg-zinc-100 p-1 dark:bg-[#26416b]" role="tablist">
                            <button
                                type="button"
                                wire:click="$set('isHtml', false)"
                                role="tab"
                                aria-selected="{{ ! $isHtml ? 'true' : 'false' }}"
                                @class([
                                    'rounded-[12px] px-3 py-1.5 text-xs font-semibold transition-colors',
                                    'bg-white text-zinc-900 shadow-sm ring-1 ring-zinc-200 dark:bg-[#1d3252] dark:text-white dark:ring-zinc-700/60' => ! $isHtml,
                                    'text-zinc-600 hover:text-zinc-900 dark:text-zinc-300' => $isHtml,
                                ])
                            >Plain text</button>
                            <button
                                type="button"
                                wire:click="$set('isHtml', true)"
                                role="tab"
                                aria-selected="{{ $isHtml ? 'true' : 'false' }}"
                                @class([
                                    'rounded-[12px] px-3 py-1.5 text-xs font-semibold transition-colors',
                                    'bg-white text-zinc-900 shadow-sm ring-1 ring-zinc-200 dark:bg-[#1d3252] dark:text-white dark:ring-zinc-700/60' => $isHtml,
                                    'text-zinc-600 hover:text-zinc-900 dark:text-zinc-300' => ! $isHtml,
                                ])
                            >HTML</button>
                        </div>
                        <p class="mt-1.5 text-[11px] text-zinc-500 dark:text-zinc-400">
                            @if ($isHtml)
                                Raw HTML is wrapped in the branded email shell. Use inline styles for safety across email clients.
                            @else
                                Plain text — blank lines become paragraphs. Wrapped in the branded email shell automatically.
                            @endif
                        </p>
                    </div>

                    {{-- Body --}}
                    @php
                        $bodyPlaceholder = $isHtml
                            ? "<p style=\"font-size:15px;line-height:1.6\">Hello there,</p>\n<p>Your HTML markup goes here.</p>"
                            : "Hi there,\n\nHere's what's new this month...\n\nThanks for being with us!";
                    @endphp
                    <div>
                        <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">{{ $isHtml ? 'HTML content' : 'Message body' }}</label>
                        <textarea
                            wire:model="body"
                            rows="12"
                            placeholder="{{ $bodyPlaceholder }}"
                            class="mt-1.5 w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2 {{ $isHtml ? 'font-mono text-xs' : 'text-sm' }} text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#0c1a36] dark:text-white"
                        ></textarea>
                        @error('body') <p class="mt-1 text-[11px] font-medium text-red-600">{{ $message }}</p> @enderror
                    </div>

                    {{-- Test send --}}
                    <div class="rounded-[12px] border border-dashed border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700/60 dark:bg-[#0c1a36]/50">
                        <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Send a test first</label>
                        <p class="mt-0.5 text-[11px] text-zinc-500 dark:text-zinc-400">Mail a single test copy to any address so you can preview before broadcasting.</p>
                        <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                            <input
                                wire:model="testEmail"
                                type="email"
                                placeholder="you@example.com"
                                class="flex-1 rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#1d3252] dark:text-white"
                            >
                            <button
                                type="button"
                                wire:click="sendTest"
                                wire:loading.attr="disabled"
                                wire:target="sendTest"
                                class="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-xs font-semibold text-zinc-700 transition-colors hover:bg-zinc-100 disabled:opacity-50 dark:border-zinc-700/60 dark:bg-[#1d3252] dark:text-zinc-300 dark:hover:bg-[#26416b]"
                            >
                                <span wire:loading.remove wire:target="sendTest">Send test</span>
                                <span wire:loading wire:target="sendTest">Sending…</span>
                            </button>
                        </div>
                        @error('testEmail') <p class="mt-1 text-[11px] font-medium text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="flex shrink-0 flex-wrap items-center justify-between gap-2 border-t border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-700/60 dark:bg-[#0c1a36]/50">
                    <p class="text-[11px] text-zinc-500 dark:text-zinc-400">Once you press <strong>Broadcast</strong>, the job runs in the background — keep the queue worker running.</p>
                    <div class="flex items-center gap-2">
                        <button type="button" wire:click="closeCompose" class="inline-flex items-center rounded-[12px] px-3.5 py-2 text-xs font-semibold text-zinc-700 transition-colors hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-[#26416b]">Cancel</button>
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="broadcast"
                            wire:confirm="Broadcast this newsletter to {{ $this->counts['active'] }} active subscribers? This cannot be undone."
                            class="inline-flex items-center gap-2 rounded-[12px] bg-blue-600 px-4 py-2 text-xs font-semibold text-white transition-colors hover:bg-blue-700 disabled:opacity-50"
                        >
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5"/>
                            </svg>
                            <span wire:loading.remove wire:target="broadcast">Broadcast to {{ number_format($this->counts['active']) }}</span>
                            <span wire:loading wire:target="broadcast">Queueing…</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    @endif
</div>
