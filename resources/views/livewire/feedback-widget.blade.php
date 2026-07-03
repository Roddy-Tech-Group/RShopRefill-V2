{{-- Storefront feedback widget. Small fixed tab on the right edge; tap to
     pop out a compact form anchored next to the button (not a full slide-out).
     Drops a ContactMessage row (= admin support ticket) and pings the
     AdminNotificationService so the team sees it in their feed. --}}
<?php

use App\Domain\Notification\Services\AdminNotificationService;
use App\Domain\Security\Services\TurnstileService;
use App\Models\ContactMessage;
use App\Support\TaggedCache;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new class extends Component {
    #[Validate('required|email|max:255')]
    public string $email = '';

    #[Validate('required|string|min:10|max:3000')]
    public string $message = '';

    public ?string $turnstileToken = null;

    public bool $submitted = false;

    public function submit(AdminNotificationService $admin): void
    {
        $this->validateTurnstile();

        $this->validate();

        $contact = ContactMessage::create([
            'user_id' => auth()->id(),
            'name' => auth()->user()?->name ?? 'Feedback visitor',
            'email' => $this->email,
            'subject' => 'Site feedback',
            'message' => $this->message,
            'ip_address' => request()->ip(),
        ]);

        $admin->push(
            type: 'contact',
            title: 'New feedback',
            message: ($contact->email).': '.\Illuminate\Support\Str::limit($contact->message, 80),
            data: ['contact_message_id' => $contact->id, 'email' => $contact->email, 'source' => 'feedback_widget'],
        );

        $this->submitted = true;
        $this->reset(['email', 'message', 'turnstileToken']);
    }

    public function startOver(): void
    {
        $this->submitted = false;
        $this->resetValidation();
    }

    protected function validateTurnstile(): void
    {
        if (! config('services.turnstile.enabled')) {
            return;
        }

        if (! config('services.turnstile.enforce_contact', true)) {
            return;
        }

        $service = TurnstileService::make();
        $result = $service->validateToken($this->turnstileToken, request()->ip());

        if ($result['status'] === TurnstileService::STATUS_SUCCESS || $result['status'] === TurnstileService::STATUS_BYPASSED) {
            return;
        }

        if ($result['status'] === TurnstileService::STATUS_TIMEOUT) {
            // Fail OPEN on timeout - feedback is non-critical, don't lock people out.
            return;
        }

        $this->recordTurnstileFailure();

        throw ValidationException::withMessages([
            'turnstileToken' => 'Security verification failed. Please try again.',
        ]);
    }

    private function recordTurnstileFailure(): void
    {
        $ip = request()->ip();
        $key = "turnstile_failures_{$ip}";
        $failures = TaggedCache::for(['security'])->get($key, 0);
        TaggedCache::for(['security'])->put($key, $failures + 1, now()->addMinutes(15));
    }
}; ?>

<div
    x-data="{
        open: false,
        turnstileWidget: null,
        toggle() {
            this.open = !this.open;
            if (this.open) {
                this.$nextTick(() => this.renderTurnstile());
            } else {
                this.resetTurnstile();
            }
        },
        renderTurnstile(attempt = 0) {
            const el = this.$refs.turnstileFeedback;
            if (! el) return;
            if (! window.turnstile) {
                if (attempt < 50) setTimeout(() => this.renderTurnstile(attempt + 1), 100);
                return;
            }
            if (this.turnstileWidget) {
                try { window.turnstile.remove(this.turnstileWidget); } catch (e) {}
                this.turnstileWidget = null;
            }
            const siteKey = el.dataset.sitekey;
            if (! siteKey) return;
            this.turnstileWidget = window.turnstile.render(el, {
                sitekey: siteKey,
                theme: 'light',
                size: 'compact',
                callback: (token) => { @this.set('turnstileToken', token); },
                'error-callback': () => { @this.set('turnstileToken', null); },
                'expired-callback': () => { @this.set('turnstileToken', null); },
            });
        },
        resetTurnstile() {
            if (this.turnstileWidget) {
                try { window.turnstile.remove(this.turnstileWidget); } catch (e) {}
                this.turnstileWidget = null;
            }
            @this.set('turnstileToken', null);
        },
    }"
    @click.outside="if (open) { open = false; resetTurnstile(); }"
    @keydown.escape.window="if (open) { open = false; resetTurnstile(); }"
    class="fixed right-0 top-1/2 z-[55] -translate-y-1/2 hidden md:block"
    aria-label="Site feedback"
>
    <div class="relative flex items-center">
        {{-- Compact popover. Anchored just left of the button, only slightly
             wider than it - no backdrop, no scroll lock. Tap outside to close. --}}
        {{-- Springy pop-out: scales up from the edge tab with a bouncy
             overshoot (same easing family as the theme-toggle spin-pop and
             the locale modal sheet) instead of the old flat fade. --}}
        <div
            x-show="open"
            x-cloak
            x-transition:enter="transition duration-[450ms] ease-[cubic-bezier(0.34,1.56,0.64,1)]"
            x-transition:enter-start="opacity-0 translate-x-8 scale-50"
            x-transition:enter-end="opacity-100 translate-x-0 scale-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100 translate-x-0 scale-100"
            x-transition:leave-end="opacity-0 translate-x-4 scale-75"
            class="absolute right-full mr-2 w-72 origin-right rounded-[12px] bg-[#eff6ff] p-4 shadow-2xl shadow-zinc-900/25 ring-1 ring-zinc-200 motion-reduce:transition-none dark:bg-[#0c1a36] dark:ring-white/15"
            role="dialog"
            aria-modal="false"
            aria-labelledby="feedback-title"
        >
            @if ($submitted)
                <div class="flex flex-col items-center text-center">
                    <span class="flex h-10 w-10 items-center justify-center rounded-full bg-emerald-50 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-300">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.25" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                        </svg>
                    </span>
                    <h4 class="mt-2 text-sm font-bold text-zinc-900 dark:text-white">Thanks for the feedback</h4>
                    <p class="mt-1 text-xs text-zinc-600 dark:text-zinc-300">A ticket has been opened with the team.</p>
                    <button
                        type="button"
                        wire:click="startOver"
                        x-on:click="$nextTick(() => renderTurnstile())"
                        class="mt-3 rounded-[12px] bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-blue-700"
                    >Send another</button>
                </div>
            @else
                <div class="mb-2 flex items-start justify-between gap-2">
                    <h3 id="feedback-title" class="text-sm font-bold text-zinc-900 dark:text-white">Send feedback</h3>
                    <button type="button" @click="open = false" aria-label="Close" class="-mt-0.5 -mr-0.5 flex h-6 w-6 items-center justify-center rounded-[6px] text-zinc-500 hover:bg-zinc-100 dark:text-white/60 dark:hover:bg-white/10">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <form wire:submit="submit" class="flex flex-col gap-2.5">
                    <input
                        wire:model="email"
                        type="email"
                        required
                        autocomplete="email"
                        placeholder="Your email"
                        class="w-full rounded-[12px] border border-zinc-300 bg-white px-3 py-2 text-xs text-zinc-900 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-white/15 dark:bg-white/5 dark:text-white dark:placeholder:text-white/40"
                    >
                    @error('email') <p class="-mt-1 text-[11px] text-red-600 dark:text-red-400">{{ $message }}</p> @enderror

                    <textarea
                        wire:model="message"
                        rows="3"
                        required
                        placeholder="Your feedback..."
                        class="w-full rounded-[12px] border border-zinc-300 bg-white px-3 py-2 text-xs text-zinc-900 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-white/15 dark:bg-white/5 dark:text-white dark:placeholder:text-white/40"
                    ></textarea>
                    @error('message') <p class="-mt-1 text-[11px] text-red-600 dark:text-red-400">{{ $message }}</p> @enderror

                    @if (config('services.turnstile.enabled') && config('services.turnstile.enforce_contact', true))
                        <div wire:ignore>
                            <div
                                x-ref="turnstileFeedback"
                                data-sitekey="{{ config('services.turnstile.site_key') }}"
                            ></div>
                        </div>
                        @error('turnstileToken') <p class="-mt-1 text-[11px] text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    @endif

                    <button
                        type="submit"
                        class="inline-flex w-full items-center justify-center rounded-[12px] bg-blue-600 px-3 py-2 text-xs font-bold text-white transition-colors hover:bg-blue-700"
                    >
                        <span wire:loading.remove wire:target="submit">Send</span>
                        <span wire:loading wire:target="submit">Sending...</span>
                    </button>
                </form>
            @endif
        </div>

        {{-- Edge tab button --}}
        <button
            type="button"
            @click="toggle()"
            :aria-expanded="open.toString()"
            aria-label="Send us feedback"
            class="flex items-center gap-2 rounded-l-[10px] bg-blue-600 px-3 py-4 text-sm font-bold uppercase tracking-wider text-white shadow-lg shadow-blue-900/30 transition-colors hover:bg-blue-700"
            style="writing-mode: vertical-rl;"
        >
            <svg class="h-4 w-4 -rotate-90 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/>
            </svg>
            Feedback
        </button>
    </div>
</div>
