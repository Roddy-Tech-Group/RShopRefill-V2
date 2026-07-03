<?php

use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\Enums\TransactionCategory;
use App\Domain\Wallet\Services\WalletService;
use App\Mail\RcoinWithdrawalApprovedMail;
use App\Mail\RcoinWithdrawalRejectedMail;
use App\Models\RcoinWithdrawal;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.admin')]
#[Title('Rcoin Withdrawals')]
class extends Component {
    /** 'pending' | 'approved' | 'paid' | 'rejected' - filter the queue. */
    public string $tab = 'pending';

    public ?int $rejectingId = null;

    public string $rejectReason = '';

    public ?int $payingId = null;

    public string $payoutReference = '';

    #[Computed]
    public function withdrawals()
    {
        return RcoinWithdrawal::query()
            ->where('status', $this->tab)
            ->with('user:id,name,email')
            ->latest()
            ->limit(50)
            ->get();
    }

    #[Computed]
    public function counts(): array
    {
        return RcoinWithdrawal::query()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['pending', 'approved', 'paid', 'rejected'], true) ? $tab : 'pending';
        $this->cancelReject();
        $this->cancelPay();
    }

    /**
     * Approve a pending request - moves to `approved` status but doesn't pay
     * out. Admin marks it `paid` after sending the funds via their own bank /
     * wallet rails (we don't auto-initiate transfers).
     */
    public function approve(int $id): void
    {
        $withdrawal = RcoinWithdrawal::query()->where('status', 'pending')->with('user')->findOrFail($id);
        $withdrawal->update([
            'status' => 'approved',
            'reviewed_by' => (string) (Auth::guard('admin')->id() ?? 'system'),
            'reviewed_at' => now(),
        ]);

        // Tell the customer the request is approved; another email follows
        // when an admin marks it as paid. Best-effort so a mail outage
        // doesn't block the admin's workflow.
        try {
            Mail::to($withdrawal->user->email)->queue(
                new RcoinWithdrawalApprovedMail(withdrawal: $withdrawal, paid: false)
            );
        } catch (\Throwable $e) {
            report($e);
        }

        unset($this->withdrawals, $this->counts);
    }

    public function startReject(int $id): void
    {
        $this->rejectingId = $id;
        $this->rejectReason = '';
    }

    public function cancelReject(): void
    {
        $this->rejectingId = null;
        $this->rejectReason = '';
    }

    /**
     * Reject - credits the Rcoin back to the user's wallet so they don't lose
     * funds, and records the reason for the audit trail. Wrapped in a
     * transaction so a partial failure (credit OK, status update fails)
     * doesn't leave the user double-charged.
     */
    public function reject(int $id): void
    {
        $this->validate([
            'rejectReason' => 'required|string|min:5|max:280',
        ]);

        $withdrawal = RcoinWithdrawal::query()->where('status', 'pending')->findOrFail($id);

        DB::transaction(function () use ($withdrawal) {
            $walletService = app(WalletService::class);
            $wallet = $walletService->getOrCreateWallet($withdrawal->user, Currency::RCOIN);

            // Credit the Rcoin back to the user as a Reversal transaction so
            // the ledger reads cleanly. Idempotency: link the credit back to
            // the original debit transaction id in metadata.
            $walletService->credit(
                wallet: $wallet,
                amount: $withdrawal->rcoin_amount,
                category: TransactionCategory::Reversal,
                description: 'Rcoin withdrawal rejected - refunded',
                metadata: [
                    'withdrawal_id' => $withdrawal->id,
                    'reversed_transaction_id' => $withdrawal->debit_transaction_id,
                    'reject_reason' => $this->rejectReason,
                ],
            );

            $withdrawal->update([
                'status' => 'rejected',
                'reject_reason' => $this->rejectReason,
                'reviewed_by' => (string) (Auth::guard('admin')->id() ?? 'system'),
                'reviewed_at' => now(),
            ]);
        });

        // Email the customer with the reason. The Rcoin has already been
        // credited back inside the transaction above, so the email can
        // confidently say "nothing was lost."
        try {
            $withdrawal->load('user');
            Mail::to($withdrawal->user->email)->queue(new RcoinWithdrawalRejectedMail(withdrawal: $withdrawal));
        } catch (\Throwable $e) {
            report($e);
        }

        $this->cancelReject();
        unset($this->withdrawals, $this->counts);
    }

    public function startPay(int $id): void
    {
        $this->payingId = $id;
        $this->payoutReference = '';
    }

    public function cancelPay(): void
    {
        $this->payingId = null;
        $this->payoutReference = '';
    }

    /**
     * Mark approved → paid. Admin enters the external payout reference (bank
     * transaction id, wallet transfer ref, mobile-money receipt) so the row
     * is auditable end-to-end.
     */
    public function markPaid(int $id): void
    {
        $this->validate([
            'payoutReference' => 'required|string|min:3|max:120',
        ]);

        $withdrawal = RcoinWithdrawal::query()->where('status', 'approved')->with('user')->findOrFail($id);
        $withdrawal->update([
            'status' => 'paid',
            'payout_reference' => $this->payoutReference,
            'paid_at' => now(),
        ]);

        // Final confirmation email - same template as the approval but with
        // `paid: true` so the copy says "your payout has been settled" and
        // includes the external reference for the customer's records.
        try {
            Mail::to($withdrawal->user->email)->queue(
                new RcoinWithdrawalApprovedMail(withdrawal: $withdrawal->refresh(), paid: true)
            );
        } catch (\Throwable $e) {
            report($e);
        }

        $this->cancelPay();
        unset($this->withdrawals, $this->counts);
    }
}; ?>

<div class="w-full px-4 py-8 sm:px-6 lg:px-8">
    <header class="mb-6">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Rcoin Withdrawals</h1>
        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">Review, approve, and mark Rcoin withdrawal requests as paid.</p>
    </header>

    {{-- Tabs --}}
    <nav class="mb-4 flex flex-wrap items-center gap-1 rounded-[12px] border border-zinc-100 bg-white p-1 dark:border-zinc-700/60 dark:bg-[#1d3252]">
        @foreach ([
            'pending' => 'Pending review',
            'approved' => 'Approved - awaiting payout',
            'paid' => 'Paid',
            'rejected' => 'Rejected',
        ] as $key => $label)
            @php $count = $this->counts[$key] ?? 0; @endphp
            <button
                type="button"
                wire:click="setTab('{{ $key }}')"
                @class([
                    'flex items-center gap-2 rounded-[12px] px-3 py-2 text-xs font-semibold transition-colors',
                    'bg-blue-600 text-white' => $tab === $key,
                    'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-[#26416b]' => $tab !== $key,
                ])
            >
                {{ $label }}
                @if ($count > 0)
                    <span @class([
                        'inline-flex items-center rounded-[5px] px-1.5 py-0.5 text-[10px] font-bold tabular-nums',
                        'bg-white/20 text-white' => $tab === $key,
                        'bg-zinc-100 text-zinc-700 dark:bg-white/10 dark:text-zinc-200' => $tab !== $key,
                    ])>{{ $count }}</span>
                @endif
            </button>
        @endforeach
    </nav>

    {{-- List --}}
    <div class="flex flex-col gap-2">
        @forelse ($this->withdrawals as $w)
            @php
                $isPending = $w->status === 'pending';
                $isApproved = $w->status === 'approved';
            @endphp
            <article class="rounded-[12px] border border-zinc-100 bg-white p-4 shadow-sm dark:border-zinc-700/60 dark:bg-[#1d3252]" wire:key="w-{{ $w->id }}">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-sm font-bold text-zinc-900 dark:text-white">{{ $w->user->name }}</h3>
                            <span class="text-[11px] text-zinc-500 dark:text-zinc-400">{{ $w->user->email }}</span>
                            <span class="font-mono text-[10px] text-zinc-500 dark:text-zinc-500">#W{{ str_pad((string) $w->id, 6, '0', STR_PAD_LEFT) }}</span>
                        </div>
                        <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-zinc-500 dark:text-zinc-400">
                            <span>Method: <span class="font-semibold text-zinc-700 dark:text-zinc-200">{{ str_replace('_', ' ', ucfirst($w->method)) }}</span></span>
                            <span>·</span>
                            <span>Requested: {{ $w->created_at->diffForHumans() }}</span>
                            @if ($w->reviewed_at)
                                <span>·</span>
                                <span>Reviewed: {{ $w->reviewed_at->diffForHumans() }}</span>
                            @endif
                            @if ($w->paid_at)
                                <span>·</span>
                                <span>Paid: {{ $w->paid_at->diffForHumans() }}</span>
                            @endif
                        </p>
                        @if (! empty($w->payout_details))
                            <pre class="mt-2 max-w-md overflow-auto rounded-[12px] bg-zinc-50 px-3 py-2 text-[11px] text-zinc-700 dark:bg-[#0c1a36] dark:text-zinc-300">{{ json_encode($w->payout_details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        @endif
                        @if ($w->reject_reason)
                            <p class="mt-2 rounded-[12px] bg-red-50 px-3 py-2 text-xs text-red-700 dark:bg-red-500/10 dark:text-red-300">
                                <span class="font-semibold">Reason:</span> {{ $w->reject_reason }}
                            </p>
                        @endif
                        @if ($w->payout_reference)
                            <p class="mt-2 inline-flex items-center gap-2 rounded-[12px] bg-emerald-50 px-3 py-1.5 text-xs text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">
                                <span class="font-semibold">Payout ref:</span>
                                <span class="font-mono">{{ $w->payout_reference }}</span>
                            </p>
                        @endif
                    </div>

                    <div class="shrink-0 text-right">
                        <p class="text-2xl font-black tabular-nums text-zinc-900 dark:text-white">{{ number_format($w->rcoin_amount) }}</p>
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Rcoin</p>
                        <p class="mt-2 text-sm font-bold tabular-nums text-emerald-600 dark:text-emerald-400">USD {{ number_format((float) $w->payout_usd, 2) }}</p>
                        @if ((float) $w->fee_usd > 0)
                            <p class="text-[10px] text-zinc-500 dark:text-zinc-400">after USD {{ number_format((float) $w->fee_usd, 2) }} fee</p>
                        @endif
                    </div>
                </div>

                {{-- Actions per status --}}
                @if ($isPending)
                    <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-700/60">
                        <button type="button" wire:click="approve({{ $w->id }})" class="rounded-[12px] bg-emerald-600 px-4 py-2 text-xs font-semibold text-white hover:bg-emerald-700">
                            Approve
                        </button>
                        <button type="button" wire:click="startReject({{ $w->id }})" class="rounded-[12px] border border-red-200 bg-red-50 px-4 py-2 text-xs font-semibold text-red-700 hover:bg-red-100 dark:border-red-500/30 dark:bg-red-500/15 dark:text-red-300">
                            Reject &amp; refund
                        </button>
                    </div>
                    @if ($rejectingId === $w->id)
                        <div class="mt-3 rounded-[12px] border border-red-200 bg-red-50/40 p-3 dark:border-red-500/30 dark:bg-red-500/10">
                            <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-200">Rejection reason (visible to the customer)</label>
                            <textarea wire:model="rejectReason" rows="2" class="mt-1 w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-sm outline-none focus:border-blue-500 dark:border-zinc-700/60 dark:bg-[#26416b] dark:text-white"></textarea>
                            @error('rejectReason') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            <div class="mt-2 flex items-center justify-end gap-2">
                                <button type="button" wire:click="cancelReject" class="rounded-[12px] border border-zinc-200 bg-white px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-100 dark:border-zinc-700/60 dark:bg-[#26416b] dark:text-zinc-200 dark:hover:bg-[#34507a]">Cancel</button>
                                <button type="button" wire:click="reject({{ $w->id }})" class="rounded-[12px] bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700">Confirm reject &amp; refund</button>
                            </div>
                        </div>
                    @endif
                @elseif ($isApproved)
                    <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-700/60">
                        <button type="button" wire:click="startPay({{ $w->id }})" class="rounded-[12px] bg-blue-600 px-4 py-2 text-xs font-semibold text-white hover:bg-blue-700">
                            Mark as paid
                        </button>
                    </div>
                    @if ($payingId === $w->id)
                        <div class="mt-3 rounded-[12px] border border-blue-200 bg-blue-50/40 p-3 dark:border-blue-500/30 dark:bg-blue-500/10">
                            <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-200">Payout reference</label>
                            <p class="text-[11px] text-zinc-500 dark:text-zinc-400">Bank txn id, wallet transfer ref, or mobile-money receipt.</p>
                            <input type="text" wire:model="payoutReference" class="mt-1 w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-sm outline-none focus:border-blue-500 dark:border-zinc-700/60 dark:bg-[#26416b] dark:text-white">
                            @error('payoutReference') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            <div class="mt-2 flex items-center justify-end gap-2">
                                <button type="button" wire:click="cancelPay" class="rounded-[12px] border border-zinc-200 bg-white px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-100 dark:border-zinc-700/60 dark:bg-[#26416b] dark:text-zinc-200 dark:hover:bg-[#34507a]">Cancel</button>
                                <button type="button" wire:click="markPaid({{ $w->id }})" class="rounded-[12px] bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-700">Confirm paid</button>
                            </div>
                        </div>
                    @endif
                @endif
            </article>
        @empty
            <div class="rounded-[12px] border border-dashed border-zinc-300 bg-white px-5 py-12 text-center text-sm text-zinc-600 dark:border-white/10 dark:bg-[#1d3252] dark:text-zinc-400">
                No {{ $tab }} withdrawals.
            </div>
        @endforelse
    </div>
</div>
