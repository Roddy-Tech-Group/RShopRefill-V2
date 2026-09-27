@php
    /**
     * "Having trouble?" and "Why did this happen?" under a failed payment.
     *
     * Reads the parent wizard's `failureCode` (the session's customer-safe
     * reason code, see CustomerFailureCopy) and its chosen method to pick a
     * family of advice: the card, mobile money, the payment service, our own
     * platform, or generic. The tips say what to change; the accordion says
     * who decided and that nothing was charged. Nothing here names a gateway
     * or a supplier.
     *
     * Pass `methodVar` when the parent wizard calls its chosen method
     * something other than `method`.
     */
    $methodVar = $methodVar ?? 'method';

    $failureHelp = [
        'tips' => [
            'card' => [__('checkout.trouble_card_1'), __('checkout.trouble_card_2')],
            'mobile_money' => [__('checkout.trouble_momo_1'), __('checkout.trouble_momo_2')],
            'provider' => [__('checkout.trouble_provider_1'), __('checkout.trouble_provider_2')],
            'platform' => [__('checkout.trouble_provider_2')],
            'generic' => [__('checkout.trouble_generic_1')],
        ],
        'why' => [
            'card' => __('checkout.why_card'),
            'mobile_money' => __('checkout.why_momo'),
            'provider' => __('checkout.why_provider'),
            'platform' => __('checkout.why_platform'),
            'generic' => __('checkout.why_generic'),
        ],
    ];
@endphp

<div
    x-data="{
        whyOpen: false,
        help: @js($failureHelp),
        family() {
            const code = failureCode || '';
            const chosen = {{ $methodVar }};
            const momo = chosen === 'mobile_money';
            if (['provider_timeout', 'provider_error'].includes(code)) return 'provider';
            if (['gateway_config_error', 'system_error'].includes(code)) return 'platform';
            if (['prompt_declined', 'prompt_timed_out', 'mobile_account_not_found', 'mobile_money_unknown'].includes(code)) return 'mobile_money';
            if (['do_not_honor', 'bank_declined', 'expired_card', 'incorrect_pin', 'authentication_failed', 'invalid_card_details', 'card_restricted', 'avs_failed', 'insufficient_funds', 'limit_exceeded'].includes(code)) return momo ? 'mobile_money' : 'card';
            if (code === 'user_cancelled') return 'generic';
            return momo ? 'mobile_money' : (['card', 'apple_pay'].includes(chosen) ? 'card' : 'generic');
        },
        tips() { return this.help.tips[this.family()] || this.help.tips.generic; },
        why() { return this.help.why[this.family()] || this.help.why.generic; },
    }"
    class="mt-5 w-full space-y-3 text-left"
    data-failure-help
>
    <div class="rounded-[12px] bg-[#f4f6f5] p-4 dark:bg-white/5">
        <p class="text-sm font-bold text-zinc-900 dark:text-white">{{ __('checkout.trouble_title') }}</p>
        <ul class="mt-2 list-disc space-y-1.5 pl-5 text-xs leading-relaxed text-zinc-600 dark:text-zinc-300">
            <template x-for="tip in tips()" :key="tip">
                <li x-text="tip"></li>
            </template>
            <li>{!! __('checkout.trouble_support', ['link' => '<a href="'.route('shop.contact').'" target="_blank" rel="noopener" class="font-semibold text-blue-600 hover:underline dark:text-blue-300">'.e(__('checkout.trouble_support_link')).'</a>']) !!}</li>
        </ul>
    </div>

    <div class="rounded-[12px] bg-[#f4f6f5] dark:bg-white/5">
        <button type="button" @click="whyOpen = ! whyOpen" :aria-expanded="whyOpen.toString()" class="flex w-full items-center justify-between gap-3 p-4 text-sm font-bold text-zinc-900 dark:text-white">
            <span>{{ __('checkout.why_title') }}</span>
            <svg class="h-4 w-4 shrink-0 text-zinc-500 transition-transform dark:text-zinc-400" :class="whyOpen ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/>
            </svg>
        </button>
        <div x-show="whyOpen" x-collapse x-cloak class="px-4 pb-4 text-xs leading-relaxed text-zinc-600 dark:text-zinc-300" x-text="why()"></div>
    </div>
</div>
