{{-- Newsletter sign-up, the row that sits above the footer's columns.

     Reference shape: a single line carrying an icon, one sentence of copy, an
     email field and the submit, rather than a stacked block with its own
     heading. It is a footnote to the page, not a section of it.

     Writes a NewsletterSubscriber with source "footer", which is how the admin
     newsletter screen already segments where an address came from (the existing
     sources are dashboard_sidebar and signup_modal). Status follows the same
     vocabulary that screen uses: active / unsubscribed.
--}}
<?php

use App\Models\NewsletterSubscriber;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\RateLimiter;

new class extends Component {
    #[Validate('required|email:rfc,dns|max:255')]
    public string $email = '';

    /**
     * Honeypot. Hidden from people and left empty by them; a bot that fills
     * every field in the form completes it.
     */
    public string $website = '';

    public bool $done = false;

    public function subscribe(): void
    {
        // Silent success for both rejections below. Telling a bot which guard
        // stopped it is how it learns to get past the guard, and a person who
        // somehow trips one is not helped by the detail either.
        if (filled($this->website)) {
            $this->done = true;
            $this->email = '';

            return;
        }

        // This form sits in the footer of every page, so it is the easiest
        // endpoint on the site to hammer. Six a minute per IP is far more than
        // a person needs and far less than a list-stuffer wants.
        $key = 'newsletter:'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 6)) {
            $this->done = true;
            $this->email = '';

            return;
        }

        RateLimiter::hit($key, 60);

        $this->validate();

        $address = strtolower(trim($this->email));

        // updateOrCreate, not create: re-subscribing an address that unsubscribed
        // has to flip it back rather than fail the unique index, and an address
        // already active must not be duplicated or have its original
        // subscribed_at overwritten.
        $existing = NewsletterSubscriber::firstWhere('email', $address);

        if ($existing) {
            $existing->update([
                'status' => 'active',
                'unsubscribed_at' => null,
                'subscribed_at' => $existing->subscribed_at ?? now(),
            ]);
        } else {
            NewsletterSubscriber::create([
                'email' => $address,
                'status' => 'active',
                'subscribed_at' => now(),
                'source' => 'footer',
            ]);
        }

        // The same confirmation either way. Telling a visitor "you were already
        // subscribed" leaks whether an address is on the list to anyone who can
        // type it into a public form.
        $this->done = true;
        $this->email = '';
    }
}; ?>

<div class="flex flex-wrap items-center justify-center gap-x-4 gap-y-3 sm:flex-nowrap">
    {{-- shrink-0 only from sm. On a phone this sentence measured 458px inside a
         390px viewport and ran off the screen, so it has to be allowed to
         shrink; the row's flex-wrap then puts it on its own line above the
         field. --}}
    <span class="flex min-w-0 shrink items-center gap-3 text-zinc-900 sm:shrink-0 dark:text-white">
        <svg class="h-6 w-6 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M4 5.5A1.5 1.5 0 0 1 5.5 4h9A1.5 1.5 0 0 1 16 5.5v13H5.5A1.5 1.5 0 0 1 4 17V5.5Z"/>
            <path d="M16 8.5h2.5A1.5 1.5 0 0 1 20 10v7a1.5 1.5 0 0 1-1.5 1.5H16"/>
            <path d="M7 8h6M7 11h6M7 14h3"/>
        </svg>
        <span class="text-[15px] font-medium">{{ __('footer.newsletter_pitch') }}</span>
    </span>

    @if ($done)
        {{-- Replaces the field rather than sitting beside it: the row is one line
             and a message added to it would push the layout around. --}}
        <p class="flex items-center gap-2 text-[15px] font-semibold text-emerald-700 dark:text-emerald-300" role="status">
            <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M4.5 12.75l6 6 9-13.5"/>
            </svg>
            {{ __('footer.newsletter_done') }}
        </p>
    @else
        <form wire:submit="subscribe" class="flex w-full max-w-[420px] items-center gap-2 sm:w-auto">
            {{-- Honeypot. aria-hidden and tabindex -1 keep it out of the tab
                 order and away from screen readers; it is moved off-canvas
                 rather than display:none because some bots skip hidden inputs. --}}
            <div class="absolute left-[-9999px] h-0 w-0 overflow-hidden" aria-hidden="true">
                <label for="newsletter-website">Website</label>
                <input id="newsletter-website" wire:model="website" type="text" tabindex="-1" autocomplete="off">
            </div>

            <div class="min-w-0 flex-1 sm:w-[260px] sm:flex-none">
                <label for="newsletter-email" class="sr-only">{{ __('footer.newsletter_label') }}</label>
                <input
                    id="newsletter-email"
                    wire:model="email"
                    type="email"
                    autocomplete="email"
                    placeholder="{{ __('footer.newsletter_placeholder') }}"
                    class="h-11 w-full rounded-full bg-[#ffffff] px-4 text-[15px] text-zinc-900 outline-none ring-1 ring-zinc-200 placeholder:text-zinc-400 focus:ring-2 focus:ring-blue-500/40 dark:bg-white/10 dark:text-white dark:ring-white/15 border-2 border-zinc-200 dark:border-white/10 focus:border-edge dark:focus:border-edge transition-colors"
                >
            </div>

            <button
                type="submit"
                class="h-11 shrink-0 rounded-[12px] bg-[#0c1337] px-5 text-[15px] font-bold text-white transition-colors hover:bg-[#1a2560] focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 dark:bg-[#fdfdfd] dark:text-[#0c1337] dark:hover:bg-[#e4e5f3]"
            >
                <span wire:loading.remove wire:target="subscribe">{{ __('footer.newsletter_cta') }}</span>
                <span wire:loading wire:target="subscribe">{{ __('footer.newsletter_sending') }}</span>
            </button>
        </form>

        @error('email')
            <p class="w-full text-center text-[13px] font-medium text-red-600 sm:w-auto sm:text-left dark:text-red-400" role="alert">{{ $message }}</p>
        @enderror
    @endif
</div>
