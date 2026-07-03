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

    public function mount(): void
    {
        $user = auth()->user();
        $this->isSaved = ! empty($user->referral_code);

        // Show the saved code, otherwise suggest one from the user's name.
        $this->code = $user->referral_code
            ?: (Str::of($user->name)->slug('')->limit(16, '')->lower()->toString() ?: 'user' . $user->id);
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
    @if (! $editing)
        {{-- Display: the referral link + copy + edit affordance --}}
        <div x-data="{ copied: false }">
            <div class="flex items-center gap-2 rounded-[12px] bg-zinc-100 px-4 py-3">
                <input
                    type="text"
                    readonly
                    x-ref="refUrl"
                    value="{{ $referralUrl }}"
                    class="flex-1 truncate bg-transparent text-sm text-zinc-700 outline-none"
                    onfocus="this.select()"
                >
                <button
                    type="button"
                    @click="navigator.clipboard.writeText($refs.refUrl.value).then(() => { copied = true; setTimeout(() => copied = false, 1500) })"
                    class="inline-flex shrink-0 items-center gap-1.5 rounded-[12px] bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-blue-700"
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
