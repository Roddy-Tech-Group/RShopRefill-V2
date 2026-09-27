{{-- Storefront feedback widget. Small fixed tab on the right edge; tap to
     pop out a compact form anchored next to the button (not a full slide-out).
     Drops a ContactMessage row (= admin support ticket) and pings the
     AdminNotificationService so the team sees it in their feed. --}}
<?php

use App\Domain\Communication\Services\AdminNotificationService;
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
            'turnstileToken' => __('navigation.feedback_security_failed'),
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

        /* ── Draggable dock ──────────────────────────────────────────────
           The tab can be moved up and down, and flipped to the other edge by
           dragging past the middle of the screen. Position is remembered per
           browser.

           `moved` is what separates a drag from a click: a pointer that
           travels more than a few pixels suppresses the click that would
           otherwise open the panel, so repositioning never posts feedback. */
        side: 'right',
        topPx: 0,
        dragging: false,
        moved: false,
        startY: 0,
        startX: 0,
        grabOffset: 0,

        initDock() {
            let saved = null;
            try { saved = JSON.parse(localStorage.getItem('feedback.dock') || 'null'); } catch (e) {}
            this.side  = (saved && saved.side === 'left') ? 'left' : 'right';
            this.topPx = (saved && typeof saved.topPx === 'number')
                ? saved.topPx
                : Math.round(window.innerHeight / 2 - this.tabHeight() / 2);
            this.clamp();
            window.addEventListener('resize', () => this.clamp());
        },

        tabHeight() {
            return this.$refs.tab ? this.$refs.tab.offsetHeight : 120;
        },

        /* Never let it leave the viewport, including after a rotate or resize. */
        clamp() {
            const max = Math.max(8, window.innerHeight - this.tabHeight() - 8);
            this.topPx = Math.min(Math.max(8, this.topPx), max);
        },

        save() {
            try {
                localStorage.setItem('feedback.dock', JSON.stringify({ side: this.side, topPx: this.topPx }));
            } catch (e) {}
        },

        startDrag(event) {
            if (event.button !== undefined && event.button !== 0) { return; }
            this.dragging = true;
            this.moved = false;
            this.startY = event.clientY;
            this.startX = event.clientX;
            this.grabOffset = event.clientY - this.$el.getBoundingClientRect().top;
            event.target.setPointerCapture?.(event.pointerId);
        },

        onDrag(event) {
            if (! this.dragging) { return; }
            if (Math.abs(event.clientY - this.startY) > 4 || Math.abs(event.clientX - this.startX) > 4) {
                this.moved = true;
                if (this.open) { this.open = false; this.resetTurnstile(); }
            }
            this.topPx = event.clientY - this.grabOffset;
            this.clamp();
            /* Crossing the midpoint re-docks it to the nearer edge. */
            this.side = event.clientX < window.innerWidth / 2 ? 'left' : 'right';
        },

        endDrag(event) {
            if (! this.dragging) { return; }
            this.dragging = false;
            event.target.releasePointerCapture?.(event.pointerId);
            if (this.moved) { this.save(); }
        },

        /* Keyboard equivalent, so the dock is not mouse-only. */
        nudge(dy) {
            this.topPx += dy;
            this.clamp();
            this.save();
        },

        flip() {
            this.side = this.side === 'right' ? 'left' : 'right';
            this.save();
        },

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
    x-init="initDock()"
    {{-- Position comes from the dock state, so the tab can be dragged. The
         translate that used to centre it is gone: top is an explicit pixel
         value now, and keeping both would double-offset it. --}}
    :style="side === 'right'
        ? `top:${topPx}px; right:0; left:auto;`
        : `top:${topPx}px; left:0; right:auto;`"
    :class="dragging && 'select-none'"
    class="fixed z-[55] hidden md:block"
    aria-label="{{ __('navigation.feedback_region') }}"
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
            :class="side === 'right' ? 'right-full mr-2 origin-right' : 'left-full ml-2 origin-left'"
            class="absolute w-72 rounded-[12px] bg-[#eff6ff] p-4 shadow-2xl shadow-zinc-900/25 ring-1 ring-zinc-200 motion-reduce:transition-none dark:bg-[#0c1a36] dark:ring-white/15"
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
                    <h4 class="mt-2 text-sm font-bold text-zinc-900 dark:text-white">{{ __('navigation.feedback_thanks') }}</h4>
                    <p class="mt-1 text-xs text-zinc-600 dark:text-zinc-300">{{ __('navigation.feedback_ticket_opened') }}</p>
                    <button
                        type="button"
                        wire:click="startOver"
                        x-on:click="$nextTick(() => renderTurnstile())"
                        class="mt-3 rounded-[12px] bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-blue-700"
                    >{{ __('navigation.feedback_send_another') }}</button>
                </div>
            @else
                <div class="mb-2 flex items-start justify-between gap-2">
                    <h3 id="feedback-title" class="text-sm font-bold text-zinc-900 dark:text-white">{{ __('navigation.feedback_title') }}</h3>
                    <button type="button" @click="open = false" aria-label="{{ __('common.close') }}" class="-mt-0.5 -mr-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#dde3ee] text-zinc-600 transition-colors hover:bg-[#cdd5e4] hover:text-zinc-900 dark:bg-white/10 dark:text-zinc-300 dark:hover:bg-white/20 dark:hover:text-white">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
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
                        placeholder="{{ __('navigation.feedback_email_placeholder') }}"
                        class="w-full rounded-[12px] border border-zinc-300 bg-white px-3 py-2 text-xs text-zinc-900 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-white/15 dark:bg-white/5 dark:text-white dark:placeholder:text-white/40"
                    >
                    @error('email') <p class="-mt-1 text-[11px] text-red-600 dark:text-red-400">{{ $message }}</p> @enderror

                    <textarea
                        wire:model="message"
                        rows="3"
                        required
                        placeholder="{{ __('navigation.feedback_message_placeholder') }}"
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
                        <span wire:loading.remove wire:target="submit">{{ __('navigation.feedback_send') }}</span>
                        <span wire:loading wire:target="submit">{{ __('navigation.feedback_sending') }}</span>
                    </button>
                </form>
            @endif
        </div>

        {{-- Edge tab button --}}
        <button
            type="button"
            x-ref="tab"
            @pointerdown="startDrag($event)"
            @pointermove="onDrag($event)"
            @pointerup="endDrag($event)"
            @pointercancel="endDrag($event)"
            {{-- A drag must not also register as a click, or moving the tab
                 would open the panel every time. --}}
            @click="moved ? (moved = false) : toggle()"
            @keydown.up.prevent="nudge(-24)"
            @keydown.down.prevent="nudge(24)"
            @keydown.left.prevent="side = 'left'; save()"
            @keydown.right.prevent="side = 'right'; save()"
            :aria-expanded="open.toString()"
            aria-label="{{ __('navigation.feedback_tab_label') }}"
            title="{{ __('navigation.feedback_drag') }}"
            :class="[side === 'right' ? 'rounded-l-[10px]' : 'rounded-r-[10px]', dragging ? 'cursor-grabbing' : 'cursor-grab']"
            class="flex cursor-grab items-center gap-2 bg-blue-600 px-3 py-4 text-sm font-bold uppercase tracking-wider text-white shadow-lg shadow-blue-900/30 transition-colors hover:bg-blue-700"
            style="writing-mode: vertical-rl;"
        >
            <svg class="h-4 w-4 -rotate-90 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/>
            </svg>
            {{ __('navigation.feedback_tab') }}
        </button>
    </div>
</div>
