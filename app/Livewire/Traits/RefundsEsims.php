<?php

namespace App\Livewire\Traits;

use App\Domain\Fulfillment\Providers\AiraloFulfillmentProvider;
use App\Domain\Fulfillment\Services\EsimRefundService;
use App\Models\Admin;
use App\Models\Esim;
use Livewire\Attributes\Computed;

/**
 * The eSIM refund request dialog, shared by the Manage eSIMs list and an
 * eSIM's detail page. Every request needs a reason; "Other" also needs a
 * written note. The service re-checks everything, live, at confirm.
 * Requesting moves no money: completing it, on the detail page, does.
 */
trait RefundsEsims
{
    public ?int $refundingEsimId = null;

    public string $refundReason = '';

    public string $refundNote = '';

    public ?string $refundError = null;

    public function openRefund(int $esimId): void
    {
        $this->resetValidation();
        $this->refundError = null;
        $this->refundReason = '';
        $this->refundNote = '';
        $this->refundingEsimId = $esimId;
        unset($this->refundingEsim);

        if ($this->refundingEsim && ($blocked = app(EsimRefundService::class)->blockedReason($this->refundingEsim))) {
            $this->refundError = $blocked;
        }
    }

    public function closeRefund(): void
    {
        $this->refundingEsimId = null;
        $this->refundError = null;
    }

    #[Computed]
    public function refundingEsim(): ?Esim
    {
        return $this->refundingEsimId
            ? Esim::with(['order', 'orderItem.product:id,name', 'user:id,name,email'])->find($this->refundingEsimId)
            : null;
    }

    /**
     * @return array<string, string>
     */
    public function refundReasons(): array
    {
        return AiraloFulfillmentProvider::REFUND_REASONS;
    }

    /**
     * "XAF 6,000.00", what the customer's wallet gets on completion.
     */
    public function refundAmountFor(Esim $esim): string
    {
        $refund = app(EsimRefundService::class)->refundFor($esim);

        return ($refund['currency']?->value ?? '').' '.number_format($refund['amount'], 2);
    }

    public function confirmRefund(): void
    {
        // A note typed under "Other" and left behind by switching reasons is dropped.
        if ($this->refundReason !== 'OTHERS') {
            $this->refundNote = '';
        }

        $this->validate([
            'refundReason' => ['required', 'in:'.implode(',', array_keys(AiraloFulfillmentProvider::REFUND_REASONS))],
            // The reason says it all, except "Other": Airalo needs that one explained.
            'refundNote' => ['nullable', 'required_if:refundReason,OTHERS', 'string', 'min:10', 'max:255'],
        ], [
            'refundReason.required' => 'Choose why this eSIM is being refunded.',
            'refundReason.in' => 'Choose why this eSIM is being refunded.',
            'refundNote.required_if' => 'Explain what happened.',
            'refundNote.min' => 'Give a little more detail (at least 10 characters).',
        ]);

        $esim = $this->refundingEsim;
        $admin = auth('admin')->user();

        if (! $esim || ! $admin instanceof Admin) {
            $this->refundError = 'This eSIM could not be found.';

            return;
        }

        $result = app(EsimRefundService::class)->request($esim, $admin, $this->refundReason, $this->refundNote);

        if (! $result['ok']) {
            $this->refundError = $result['message'];

            return;
        }

        $this->closeRefund();
        session()->flash('status', $result['message']);
        $this->afterEsimRefunded();
    }

    /**
     * Hook for the page to refresh what it shows.
     */
    protected function afterEsimRefunded(): void {}
}
