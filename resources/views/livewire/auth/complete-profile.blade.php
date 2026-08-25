<?php

use App\Domain\Communication\Listeners\System\WelcomeListener;
use App\Http\Middleware\CaptureReferralCookie;
use App\Models\NewsletterSubscriber;
use App\Models\Referral;
use App\Models\User;
use App\Support\FeatureFlag;
use App\Support\PhoneCountries;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Propaganistas\LaravelPhone\PhoneNumber;
use Propaganistas\LaravelPhone\Rules\Phone;

/**
 * Finishes a Google sign-up.
 *
 * Google returns a name, an email and a picture, so an account created from it
 * is missing everything the register wizard collects. This page asks for that
 * remainder once, with the SAME rules the wizard applies - the country list
 * libphonenumber knows, a phone validated against that country's numbering plan
 * and stored as E.164, and the one-line-one-account uniqueness check - so an
 * account finished here is indistinguishable from one registered by password.
 *
 * EnsureProfileCompleted holds the customer here until this form is submitted:
 * signup is simply not finished until then, so the page offers no way past it.
 * The traffic is one-way in both directions - an account that has already
 * finished is sent to the dashboard rather than shown a step it has completed.
 */
new #[Layout('components.layouts.auth.centered')] class extends Component {
    public string $country = PhoneCountries::DEFAULT_ISO;

    public string $phone = '';

    public string $gender = '';

    public bool $acceptedTerms = false;

    public bool $newsletterOptIn = false;

    public string $referral = '';

    public function mount(): void
    {
        $user = Auth::user();

        // This is a signup step, not a settings page. An account that already
        // finished has no business here, and submitting it again would rewrite
        // their phone, country and gender - profile settings is where those are
        // edited.
        if (! $user->needsProfileCompletion()) {
            $this->redirect(route('dashboard', absolute: false), navigate: true);

            return;
        }

        // A returning half-finished account keeps whatever it already had, so
        // re-submitting never asks for the same answer twice.
        $this->country = $user->country ?: PhoneCountries::DEFAULT_ISO;
        $this->gender = $user->gender ?: '';

        // The ?ref invite cookie normally attributes at sign-in, before this
        // page is reached; read it anyway so a code that survived still shows,
        // and stays editable for someone typing a friend's code by hand.
        $this->referral = (string) request()->cookie(CaptureReferralCookie::COOKIE_NAME, '');
    }

    /**
     * Store the missing details and lift the hold.
     */
    public function save(): void
    {
        $user = Auth::user();

        // Same rule as mount(), enforced on the write too: a Livewire call can
        // be made without ever mounting the page.
        if (! $user->needsProfileCompletion()) {
            $this->redirect(route('dashboard', absolute: false), navigate: true);

            return;
        }

        $validated = $this->validate([
            'country' => ['required', 'string', Rule::in(PhoneCountries::isoCodes())],
            // The trunk 0 a customer may type is tolerated here and stripped
            // when we store E.164, exactly as the register wizard does.
            'phone' => ['required', 'string', 'max:32', (new Phone)->countryField('country')],
            'gender' => ['required', 'in:male,female,other'],
            'acceptedTerms' => ['accepted'],
            'referral' => ['nullable', 'string', 'max:32'],
        ]);

        // Canonicalise to E.164 (+2348031234567) before the uniqueness check:
        // a national-number comparison could not see that 0803... and +234803...
        // are the same line. One verified line, one account.
        $phoneE164 = (new PhoneNumber($validated['phone'], [$validated['country']]))->formatE164();

        if (User::where('phone', $phoneE164)->whereKeyNot($user->getKey())->exists()) {
            throw ValidationException::withMessages([
                'phone' => 'This phone number is already registered to another account.',
            ]);
        }

        $user->forceFill([
            'country' => $validated['country'],
            'phone' => $phoneE164,
            'gender' => $validated['gender'],
            // Set only on a submission that passed the terms checkbox, so this
            // timestamp doubles as the record of when they accepted.
            'profile_completed_at' => now(),
        ])->save();

        // Newsletter opt-in feeds the same list the admin Newsletter page
        // broadcasts to. firstOrCreate keeps it idempotent, and an address that
        // had previously unsubscribed is re-activated because this tick is an
        // explicit, current choice.
        if ($this->newsletterOptIn && FeatureFlag::on('newsletter_signup')) {
            $subscriber = NewsletterSubscriber::firstOrCreate(
                ['email' => $user->email],
                ['status' => 'active', 'subscribed_at' => now(), 'source' => 'google_signup'],
            );

            if ($subscriber->status !== 'active') {
                $subscriber->update([
                    'status' => 'active',
                    'subscribed_at' => now(),
                    'unsubscribed_at' => null,
                ]);
            }
        }

        // Referral attribution. GoogleAuthController already ran the cookie
        // against this account at sign-up, and Referral::attribute is idempotent
        // per referred user, so a code typed here can only fill a gap - it can
        // never overwrite an attribution that already stuck.
        Referral::attribute($user, $validated['referral'] !== ''
            ? $validated['referral']
            : (string) request()->cookie(CaptureReferralCookie::COOKIE_NAME, ''));
        Cookie::queue(Cookie::forget(CaptureReferralCookie::COOKIE_NAME));

        // The welcome email waited for this moment. WelcomeListener suppressed
        // it at the Google callback because the account was still owing this
        // form, so an abandoned signup is never welcomed; now that the hold is
        // lifted the account is real and the greeting knows their name. The
        // listener's correlation id keeps it once-per-account for life, so
        // calling it here can never produce a second welcome. A mail failure
        // must not cost the customer their finished registration, which is
        // already saved above.
        try {
            app(WelcomeListener::class)->handle(new Registered($user));
        } catch (\Throwable $e) {
            Log::warning('Welcome email failed after signup completion', [
                'user_id' => $user->getKey(),
                'error' => $e->getMessage(),
            ]);
        }

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }

}; ?>

<div class="flex flex-col sm:flex-1">
    <div class="mx-auto flex w-full max-w-sm flex-col py-3 sm:flex-1 sm:justify-center sm:py-6">

        <h1 class="text-center text-2xl font-bold tracking-tight text-zinc-900">Complete your registration</h1>

        <form wire:submit="save" class="mt-6 flex flex-col gap-5">

            {{-- Country dial-code + phone. Same two-segment control as the
                 register wizard: the left segment picks the country (and its
                 +code), the right takes the national number. libphonenumber
                 validates the pair server-side. --}}
            <div
                x-data="{
                    countries: @js(App\Support\PhoneCountries::all()),
                    selected: @js($country),
                    search: '',
                    open: false,
                    get current() { return this.countries.find(c => c.iso === this.selected) || this.countries[0]; },
                    get matches() {
                        const q = this.search.trim().toLowerCase();
                        if (! q) return this.countries;
                        return this.countries.filter(c =>
                            c.name.toLowerCase().includes(q) || c.iso.toLowerCase().includes(q) || ('+' + c.dial).includes(q)
                        );
                    },
                    choose(iso) {
                        this.selected = iso;
                        $wire.country = iso;
                        this.open = false;
                        this.search = '';
                        this.$nextTick(() => this.$refs.phoneInput?.focus());
                    },
                }"
                @click.outside="open = false"
            >
                <label for="completePhone" class="mb-1.5 block text-sm font-medium text-zinc-700">Phone number</label>
                <div class="relative flex items-stretch rounded-[12px] border border-zinc-300 bg-white transition-colors focus-within:border-blue-500 focus-within:ring-2 focus-within:ring-blue-500/15">
                    <button
                        type="button"
                        @click="open = ! open"
                        :aria-expanded="open"
                        aria-label="Select country dialing code"
                        class="flex shrink-0 items-center gap-1.5 rounded-l-[12px] border-r border-zinc-300 px-3 text-sm text-black outline-none hover:bg-zinc-50"
                    >
                        <span class="text-base leading-none" x-text="current.flag"></span>
                        <span class="font-medium" x-text="'+' + current.dial"></span>
                        <svg class="h-4 w-4 text-zinc-500" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
                    </button>

                    <input
                        wire:model="phone"
                        x-ref="phoneInput"
                        id="completePhone"
                        type="tel"
                        inputmode="numeric"
                        autocomplete="tel-national"
                        required
                        :placeholder="current.example"
                        {{-- text-base on mobile: iOS Safari zooms the viewport
                             when a focused input is under 16px. --}}
                        class="w-full min-w-0 rounded-r-[12px] bg-transparent px-3.5 py-3 text-base text-black placeholder:text-zinc-400 outline-none sm:py-2.5 sm:text-sm"
                    >
                </div>

                <div
                    x-show="open"
                    x-collapse
                    x-cloak
                    @keydown.escape.stop="open = false"
                    class="mt-2 overflow-hidden rounded-[12px] border border-zinc-200 bg-white shadow-xl"
                >
                    <div class="border-b border-zinc-100 p-2">
                        <input
                            x-model="search"
                            type="text"
                            placeholder="Search country or code"
                            class="w-full rounded-[8px] border border-zinc-300 bg-white px-3 py-2.5 text-base text-black placeholder:text-zinc-400 outline-none focus:border-blue-500 sm:py-2 sm:text-sm"
                            @keydown.enter.prevent="if (matches.length) choose(matches[0].iso)"
                        >
                    </div>
                    <ul class="max-h-52 overflow-y-auto overscroll-contain py-1" role="listbox">
                        <template x-for="c in matches" :key="c.iso">
                            <li>
                                <button
                                    type="button"
                                    @click="choose(c.iso)"
                                    class="flex w-full items-center gap-2.5 px-3 py-2 text-left text-sm text-zinc-700 hover:bg-zinc-50"
                                    :class="c.iso === selected && 'bg-zinc-50'"
                                >
                                    <span class="text-base leading-none" x-text="c.flag"></span>
                                    <span class="flex-1 truncate" x-text="c.name"></span>
                                    <span class="text-zinc-500" x-text="'+' + c.dial"></span>
                                </button>
                            </li>
                        </template>
                        <li x-show="! matches.length" class="px-3 py-3 text-center text-sm text-zinc-500">No match</li>
                    </ul>
                </div>
                @error('country') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                @error('phone') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            {{-- Gender drives the default avatar until the customer uploads one. --}}
            <div>
                <span class="mb-1.5 block text-sm font-medium text-zinc-700">Gender</span>
                <div class="grid grid-cols-3 gap-1 rounded-[12px] bg-zinc-100 p-1">
                    @foreach ([
                        ['value' => 'male',   'label' => 'Male'],
                        ['value' => 'female', 'label' => 'Female'],
                        ['value' => 'other',  'label' => 'Other'],
                    ] as $opt)
                        <button
                            type="button"
                            wire:click="$set('gender', '{{ $opt['value'] }}')"
                            class="rounded-[12px] px-3 py-3 text-sm font-semibold transition-all active:scale-95 sm:py-2.5 {{ $gender === $opt['value'] ? 'bg-blue-600 text-white shadow-sm' : 'text-zinc-600 hover:text-zinc-900' }}"
                            aria-pressed="{{ $gender === $opt['value'] ? 'true' : 'false' }}"
                        >
                            {{ $opt['label'] }}
                        </button>
                    @endforeach
                </div>
                @error('gender') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            {{-- Referral code (optional). Someone who arrived through an invite
                 link was already attributed at sign-in; this is for a code
                 shared by hand. --}}
            <div>
                <label for="completeReferral" class="mb-1.5 block text-sm font-medium text-zinc-700">
                    Referral code <span class="font-normal text-zinc-500">(optional)</span>
                </label>
                <input
                    wire:model="referral"
                    id="completeReferral"
                    type="text"
                    autocomplete="off"
                    placeholder="Enter a friend's referral code"
                    class="w-full rounded-[12px] border border-zinc-300 bg-white px-3.5 py-3 text-base text-black placeholder:text-zinc-400 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 sm:py-2.5 sm:text-sm"
                >
                @error('referral') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <label class="flex cursor-pointer items-start gap-3 text-sm text-zinc-600">
                <input wire:model="acceptedTerms" type="checkbox" required class="mt-0.5 h-5 w-5 shrink-0 rounded-full border-zinc-300 text-blue-600 focus:ring-blue-500/30">
                <span>* I declare that I have read and agree to the <a href="{{ route('shop.terms') }}" target="_blank" class="text-blue-600 underline underline-offset-2 hover:text-blue-700">terms of use</a> and <a href="{{ route('shop.privacy') }}" target="_blank" class="text-blue-600 underline underline-offset-2 hover:text-blue-700">privacy notice</a></span>
            </label>
            @error('acceptedTerms') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

            {{-- Newsletter opt-in. Hidden when features.newsletter_signup_enabled
                 is off, and re-checked server-side on save. --}}
            @feature('newsletter_signup')
            <label class="flex cursor-pointer items-start gap-3 text-sm text-zinc-600">
                <input wire:model="newsletterOptIn" type="checkbox" class="mt-0.5 h-5 w-5 shrink-0 rounded-full border-zinc-300 text-blue-600 focus:ring-blue-500/30">
                <span>Sign me up for the newsletter, deals and product news. You can unsubscribe any time.</span>
            </label>
            @endfeature

            <button
                type="submit"
                wire:loading.attr="disabled"
                class="flex w-full items-center justify-center gap-2 rounded-[12px] bg-blue-600 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-blue-600/25 transition-colors hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/50 disabled:opacity-60 sm:py-2.5"
            >
                <span wire:loading.remove wire:target="save">Complete signup</span>
                <span wire:loading wire:target="save">Saving...</span>
            </button>
        </form>

    </div>
</div>
