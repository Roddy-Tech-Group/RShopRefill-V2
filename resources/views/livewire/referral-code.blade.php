<?php

use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component {
    /** The editable referral code. */
    public string $code = '';

    /** Whether the inline editor is open. */
    public bool $editing = false;

    /** True once the user has saved a code (vs. just a suggestion). */
    public bool $isSaved = false;

    /**
     * 'default' is the compact link box (top bar panel); 'hero' is a large
     * centred pill row with share + Copy; 'dark' is the Referral Hero design on
     * the For Partners Refer & Earn tab (dark field, mono link, pencil to edit
     * the code, white Copy button).
     */
    public string $variant = 'default';

    public function mount(): void
    {
        $user = auth()->user();

        // Every shared link must carry a code that is actually saved. The link
        // used to show a suggested code (from the name) that was never stored,
        // and Referral::attribute matches ?ref= against users.referral_code, so
        // anyone signing up through it was never recorded as a referral. A
        // customer without a code now gets the suggestion saved, made unique,
        // the first time the link is shown; they can still change it.
        if (empty($user->referral_code)) {
            $user->forceFill(['referral_code' => $this->uniqueCodeFor($user)])->save();
        }

        $this->isSaved = true;
        $this->code = (string) $user->referral_code;
    }

    /** A URL-safe 3-24 character code from the name, unique across customers. */
    private function uniqueCodeFor($user): string
    {
        $base = Str::of((string) $user->name)->slug('')->limit(16, '')->lower()->toString();
        if (strlen($base) < 3) {
            $base = 'user'.$user->id;
        }

        $candidate = $base;
        while (\App\Models\User::query()->where('referral_code', $candidate)->whereKeyNot($user->id)->exists()) {
            $candidate = $base.random_int(100, 9999);
        }

        return $candidate;
    }

    protected function rules(): array
    {
        return [
            // URL-safe, 3-24 chars, unique across customers.
            'code' => [
                'required', 'string', 'min:3', 'max:24',
                'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('users', 'referral_code')->ignore(auth()->id()),
            ],
        ];
    }

    protected function messages(): array
    {
        return [
            'code.regex' => 'Use only letters, numbers, dashes or underscores.',
            'code.unique' => 'That code is taken. Try another.',
        ];
    }

    public function save(): void
    {
        $data = $this->validate();
        auth()->user()->update(['referral_code' => $data['code']]);
        $this->isSaved = true;
        $this->editing = false;
    }

    public function cancel(): void
    {
        $this->resetErrorBag();
        $this->mount();
        $this->editing = false;
    }

    public function with(): array
    {
        return [
            'referralUrl' => rtrim(url('/'), '/') . '/?ref=' . $this->code,
        ];
    }
}; ?>

<div>
    @if ($variant === 'dark')
        {{-- Referral Hero design: a dark field with the link in mono type, a
             pencil that opens the custom-code editor in the same row, and a
             white Copy button. Colours are literal hexes on purpose, so the
             dashboard's dark-mode surface remaps leave this always-dark card
             alone. --}}
        @if (! $editing)
            <div x-data="{ copied: false }" class="flex w-full items-center gap-1.5 rounded-[12px] border border-white/[0.08] bg-[#0a0a0c] py-1 pl-4 pr-1">
                <input
                    type="text"
                    readonly
                    x-ref="refUrl"
                    value="{{ $referralUrl }}"
                    aria-label="Your referral link"
                    class="min-w-0 flex-1 truncate bg-transparent text-[13px] leading-[1.4] text-[#e9e9ef] outline-none"
                    onfocus="this.select()"
                >
                <button
                    type="button"
                    wire:click="$set('editing', true)"
                    aria-label="{{ $isSaved ? 'Change your referral code' : 'Set a custom referral code' }}"
                    title="{{ $isSaved ? 'Change your referral code' : 'Set a custom referral code' }}"
                    class="grid h-7 w-7 flex-none place-items-center rounded-[8px] text-white/70 transition-colors hover:bg-white/[0.09]"
                >
                    <svg class="h-[16px] w-[16px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4z"/><path d="M14.5 5.5l3 3"/></svg>
                </button>
                <button
                    type="button"
                    @click="navigator.clipboard.writeText($refs.refUrl.value).then(() => { copied = true; setTimeout(() => copied = false, 1800) })"
                    class="flex flex-none items-center gap-1.5 rounded-[9px] bg-[#fdfdfd] px-3.5 py-1.5 text-[13px] leading-none text-[#0c1337] transition-colors hover:bg-[#e4e5f3]"
                >
                    <span x-show="!copied"><svg class="h-[15px] w-[15px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2.5"/><path d="M15 5.5A2.5 2.5 0 0 0 12.5 3h-7A2.5 2.5 0 0 0 3 5.5v7A2.5 2.5 0 0 0 5.5 15"/></svg></span>
                    <span x-show="copied" x-cloak><svg class="h-[15px] w-[15px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4.5 12.75l6 6 9-13.5"/></svg></span>
                    <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
                </button>
            </div>
        @else
            <div class="w-full rounded-[20px] border border-white/[0.08] bg-[#0a0a0c] p-[14px] text-left">
                <label for="referral_code_dark" class="px-3 text-sm font-semibold text-white">Your custom referral code</label>
                <div class="mt-2 flex items-center gap-2.5">
                    <input
                        id="referral_code_dark"
                        type="text"
                        wire:model="code"
                        wire:keydown.enter="save"
                        maxlength="24"
                        class="min-w-0 flex-1 rounded-[13px] border border-white/10 bg-[#17171a] px-4 py-3 text-[15px] text-[#e9e9ef] outline-none focus:border-white/30"
                    >
                    <button type="button" wire:click="save" class="flex-none rounded-[13px] bg-[#fdfdfd] px-5 py-3 text-[15px] font-bold text-[#0c1337] transition-colors hover:bg-[#e4e5f3]">Save</button>
                    <button type="button" wire:click="cancel" class="flex-none rounded-[13px] px-4 py-3 text-[15px] font-semibold text-white/70 transition-colors hover:bg-white/[0.09]">Cancel</button>
                </div>
                <p class="mt-2 px-3 text-xs text-[#87878f]">Letters, numbers, dashes and underscores. Make it memorable, like your name.</p>
                @error('code') <p class="mt-1 px-3 text-xs text-red-400">{{ $message }}</p> @enderror
            </div>
        @endif
    @elseif (! $editing && $variant === 'hero')
        {{-- Hero: one wide pill holding the link, a share button (where the
             device can share) and a Copy button, with the code line centred
             underneath. --}}
        <div x-data="{ copied: false, canShare: typeof navigator !== 'undefined' && !! navigator.share }" class="mx-auto w-full max-w-[640px]">
            <div class="flex items-center gap-2 rounded-full border-2 border-[#dfe3e1] bg-[#ffffff] p-1.5 pl-5 dark:border-white/10">
                <input
                    type="text"
                    readonly
                    x-ref="refUrl"
                    value="{{ $referralUrl }}"
                    aria-label="Your referral link"
                    class="min-w-0 flex-1 truncate bg-transparent text-sm text-zinc-800 outline-none dark:text-zinc-100"
                    onfocus="this.select()"
                >
                <button
                    type="button"
                    x-show="canShare"
                    x-cloak
                    @click="navigator.share({ url: $refs.refUrl.value }).catch(() => {})"
                    aria-label="Share your referral link"
                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-[#0c1337] transition-colors hover:bg-[#f4f6f5] dark:text-zinc-200 dark:hover:bg-white/10"
                >
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7.217 10.907a2.25 2.25 0 100 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186l9.566-5.314m-9.566 7.5l9.566 5.314m0 0a2.25 2.25 0 103.935 2.186 2.25 2.25 0 00-3.935-2.186zm0-12.814a2.25 2.25 0 103.933-2.185 2.25 2.25 0 00-3.933 2.185z"/></svg>
                </button>
                <button
                    type="button"
                    @click="navigator.clipboard.writeText($refs.refUrl.value).then(() => { copied = true; setTimeout(() => copied = false, 1500) })"
                    class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-[#0c1337] px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-[#1a2560] dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200"
                >
                    <svg x-show="!copied" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 01-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 011.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 00-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 01-1.125-1.125v-9.25"/>
                    </svg>
                    <svg x-show="copied" x-cloak class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                    </svg>
                    <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
                </button>
            </div>

            <div class="mt-2 flex items-center justify-center gap-1.5 text-sm text-zinc-600 dark:text-zinc-400">
                <span>Your code:</span>
                <span class="font-semibold text-zinc-900 dark:text-white">{{ $code }}</span>
                <button type="button" wire:click="$set('editing', true)" class="font-semibold text-[#0c1337] underline underline-offset-2 transition-colors hover:text-[#1a2560] dark:text-zinc-200">
                    {{ $isSaved ? 'Change' : 'Set a custom code' }}
                </button>
            </div>
        </div>
    @elseif (! $editing)
        {{-- Display: the referral link + copy + edit affordance --}}
        <div x-data="{ copied: false }">
            <div class="flex items-center gap-2 rounded-[12px] bg-zinc-100 py-1.5 pl-3.5 pr-1.5">
                <input
                    type="text"
                    readonly
                    x-ref="refUrl"
                    value="{{ $referralUrl }}"
                    class="flex-1 truncate bg-transparent text-[13px] text-zinc-700 outline-none"
                    onfocus="this.select()"
                >
                <button
                    type="button"
                    @click="navigator.clipboard.writeText($refs.refUrl.value).then(() => { copied = true; setTimeout(() => copied = false, 1500) })"
                    class="inline-flex shrink-0 items-center gap-1.5 rounded-[10px] bg-blue-600 px-3 py-1.5 text-[13px] font-semibold text-white transition-colors hover:bg-blue-700"
                >
                    <svg x-show="!copied" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 01-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 011.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 00-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 01-1.125-1.125v-9.25"/>
                    </svg>
                    <svg x-show="copied" x-cloak class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                    </svg>
                    <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
                </button>
            </div>

            <div class="mt-2 flex items-center gap-1.5 text-sm text-zinc-600">
                <span>Your code:</span>
                <span class="font-semibold text-zinc-900">{{ $code }}</span>
                <button type="button" wire:click="$set('editing', true)" class="font-semibold text-blue-600 underline underline-offset-2 transition-colors hover:text-blue-700">
                    {{ $isSaved ? 'Change' : 'Set a custom code' }}
                </button>
            </div>
        </div>
    @else
        {{-- Edit: pick a custom code --}}
        <div class="rounded-[12px] bg-zinc-100 p-4">
            <label for="referral_code" class="text-sm font-semibold text-zinc-900">Your custom referral code</label>
            <p class="mt-0.5 text-xs text-zinc-600">Make it memorable, like your name. Letters, numbers, dashes and underscores only.</p>
            <div class="mt-2 flex items-center gap-2">
                <input
                    id="referral_code"
                    type="text"
                    wire:model="code"
                    wire:keydown.enter="save"
                    maxlength="24"
                    class="flex-1 rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15"
                >
                <button type="button" wire:click="save" class="shrink-0 rounded-[12px] bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-blue-700">
                    Save
                </button>
                <button type="button" wire:click="cancel" class="shrink-0 rounded-[12px] bg-white px-4 py-2 text-sm font-semibold text-zinc-700 ring-1 ring-zinc-200 transition-colors hover:bg-zinc-50">
                    Cancel
                </button>
            </div>
            @error('code') <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
    @endif
</div>
