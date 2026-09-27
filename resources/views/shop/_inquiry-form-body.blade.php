{{--
    The inquiry form BODY: the two-column intro + form card, without any page
    chrome around it.

    Split out of _inquiry-form so the dashboard's Partnership program tab can
    render the same form the storefront page does. That tab used to be a button
    that sent a signed-in customer out to /influencers-affiliates to start
    again; the form is the thing, so both surfaces include this and only differ
    in what wraps it.

    Takes the same variables as _inquiry-form - see that file for the list.
--}}
@php
    // A 12px field with a 1px edge that turns blue and gains a soft ring on
    // focus. The placeholder colour is set outright: the browser's default is
    // half the text colour, which read as barely there on the multiline field.
    $field = 'w-full rounded-[12px] border border-zinc-300 bg-white px-4 py-3 text-sm text-zinc-900 placeholder:text-zinc-500 outline-none transition-colors focus:border-edge focus:ring-2 focus:ring-edge/20';
    // What the left column falls back to when a page passes no ladder or list.
    $steps = ! empty($steps ?? []) ? $steps : ['Send us your details', 'We review your inquiry', 'You hear back from us by email'];
    $benefits = ! empty($benefits ?? []) ? $benefits : ['No commitment required', 'Free to join', 'Quick and simple process'];
    $fieldMultiline = str_replace('rounded-full', 'rounded-[12px]', $field);
    // A field that failed validation wears a red edge, tells assistive tech it
    // is invalid, and points at its own message by id.
    $fieldFor = fn (string $name, bool $multiline = false) => ($multiline ? $fieldMultiline : $field).($errors->has($name) ? ' border-red-400 focus:border-red-500' : '');
    // Surfaces follow the ground. On the storefront's grey page the panel is
    // white and the form card grey; inside the dashboard's white card the panel
    // is grey and the form card white. Each layer differs from the one under
    // it, so the block reads as a panel in light mode wherever it sits. The
    // greys are classes the dark theme knows: bg-zinc-100 sinks to the page
    // black there, so the card still stands apart from the ash panel.
    $onPage = ($ground ?? 'card') === 'page';
    $panelClass = $onPage
        ? 'rounded-[12px] bg-white p-4 shadow-md shadow-zinc-900/[0.06] sm:p-6 lg:p-8 dark:shadow-none'
        : 'rounded-[12px] bg-[#f4f6f5] p-4 sm:p-6 lg:p-8 dark:bg-white/5';
    $formCardClass = $onPage
        ? 'rounded-[12px] bg-zinc-100 p-6 sm:p-8'
        : 'rounded-[12px] bg-white p-6 shadow-md shadow-zinc-900/[0.06] sm:p-8 dark:shadow-none';
    $benefitsClass = $onPage ? 'bg-zinc-100' : 'bg-white/70 dark:bg-white/5';
    $invalid = fn (string $name) => $errors->has($name) ? 'aria-invalid="true" aria-describedby="inquiry-'.$name.'-error"' : '';
@endphp

        {{-- One tinted ground under both columns; the form is a white card on it,
             so the page reads as a pitch beside an application rather than two
             equal boxes. --}}
        <div class="{{ $panelClass }}">
        <div class="grid grid-cols-1 gap-8 lg:grid-cols-2 lg:gap-12">

            {{-- Pitch: the icon tile, the lead, a large "How it works", the
                 ladder and the reassurance panel. --}}
            <div class="lg:py-4">
                @if (! empty($icon ?? null))
                    <span class="flex h-16 w-16 items-center justify-center rounded-[12px] bg-zinc-900 text-white dark:bg-white dark:text-zinc-900">
                        <x-ui.anim-icon :file="$icon" tone="inherit" class="h-8 w-8" />
                    </span>
                @endif

                <p class="mt-6 text-base leading-relaxed text-zinc-700 dark:text-zinc-300">{{ $intro }}</p>

                {{-- Numbered rather than bulleted because these are stages in
                     order, and the first is the one the form itself completes. --}}
                <h2 class="mt-8 text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl dark:text-white">How it works</h2>
                <ol class="mt-5 space-y-3">
                    @foreach ($steps as $i => $step)
                        <li class="flex items-center gap-3.5">
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-[8px] {{ $i === 0 ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' : 'bg-transparent text-zinc-600 ring-1 ring-zinc-300 dark:text-zinc-300 dark:ring-white/20' }} text-xs font-bold">{{ $i + 1 }}</span>
                            <span class="text-base text-zinc-800 dark:text-zinc-200">{{ $step }}</span>
                        </li>
                    @endforeach
                </ol>

                {{-- What this costs the person filling the form in, which is nothing. --}}
                <ul class="mt-8 space-y-3 rounded-[12px] {{ $benefitsClass }} p-6">
                    @foreach ($benefits as $benefit)
                        <li class="flex items-center gap-3">
                            <svg class="h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                            <span class="text-base text-zinc-800 dark:text-zinc-200">{{ $benefit }}</span>
                        </li>
                    @endforeach
                </ul>

                <a href="{{ route('shop.contact') }}" wire:navigate class="mt-6 inline-flex items-center text-sm font-semibold text-blue-700 hover:text-blue-800">
                    Or visit the general contact page
                </a>
            </div>

            {{-- Form --}}
            <div>
                <div class="{{ $formCardClass }}">
                    @if (session('contact_sent'))
                        <div class="flex flex-col items-center py-10 text-center">
                            <span class="flex h-14 w-14 items-center justify-center rounded-[12px] bg-emerald-100">
                                <svg class="h-7 w-7 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                            </span>
                            <h2 class="mt-4 text-xl font-bold text-zinc-900">Inquiry received</h2>
                            <p class="mt-1.5 max-w-sm text-sm leading-relaxed text-zinc-600">Thanks for reaching out. Our team will review your inquiry and get back to you shortly.</p>
                            <a href="{{ url()->current() }}" wire:navigate class="mt-6 inline-flex items-center gap-2 rounded-full bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-blue-700">Submit another</a>
                        </div>
                    @else
                        {{-- The required-fields note sits under the fields, beside the
                             button, so the heading is the invitation alone. --}}
                        <h2 class="mb-6 text-2xl font-bold tracking-tight text-zinc-900">{{ $formTitle ?? $title.' inquiry' }}</h2>

                        {{-- Field ids carry a prefix so they stay unique on a page
                             that has other forms (the dashboard's partners tab).
                             Limits mirror the handler's rules, so the browser stops
                             what the server would refuse. The submit disables
                             itself once pressed, so a slow connection cannot send
                             the same inquiry twice. --}}
                        <form
                            method="POST"
                            action="{{ route($postRoute) }}"
                            class="space-y-5"
                            x-data="{ sending: false, valid: false }"
                            x-init="valid = $el.checkValidity()"
                            @input="valid = $el.checkValidity()"
                            @change="valid = $el.checkValidity()"
                            @submit="sending = true"
                        >
                            @csrf

                            {{-- Honeypot --}}
                            <div class="hidden" aria-hidden="true">
                                <label>Website<input type="text" name="website_hp" tabindex="-1" autocomplete="off"></label>
                            </div>

                            @if ($errors->any())
                                <div role="alert" class="rounded-[12px] bg-red-50 px-4 py-3 text-sm text-red-700">
                                    Please check the highlighted fields below.
                                </div>
                            @endif

                            {{-- Half-width cells are columns whose label takes the spare
                                 height, so a label that wraps ("Profile or website link
                                 (optional)") no longer pushes its field below the one
                                 beside it. --}}
                            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                <div class="flex flex-col">
                                    <label for="inquiry-name" class="mb-2 block flex-1 text-sm font-semibold text-zinc-900">Your name <span class="text-zinc-500" aria-hidden="true">*</span></label>
                                    <input id="inquiry-name" name="name" type="text" required autocomplete="name" maxlength="255" value="{{ old('name', auth()->user()?->name) }}" placeholder="Your name" class="{{ $fieldFor('name') }}" {!! $invalid('name') !!}>
                                    @error('name') <p id="inquiry-name-error" role="alert" class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div class="flex flex-col">
                                    <label for="inquiry-email" class="mb-2 block flex-1 text-sm font-semibold text-zinc-900">{{ $emailLabel ?? 'Work email' }} <span class="text-zinc-500" aria-hidden="true">*</span></label>
                                    <input id="inquiry-email" name="email" type="email" required autocomplete="email" inputmode="email" spellcheck="false" maxlength="255" value="{{ old('email', auth()->user()?->email) }}" placeholder="{{ $emailPlaceholder ?? 'you@company.com' }}" class="{{ $fieldFor('email') }}" {!! $invalid('email') !!}>
                                    @error('email') <p id="inquiry-email-error" role="alert" class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                <div class="flex flex-col">
                                    <label for="inquiry-company" class="mb-2 block flex-1 text-sm font-semibold text-zinc-900">{{ $companyLabel ?? 'Company' }} <span class="text-zinc-500" aria-hidden="true">*</span></label>
                                    <input id="inquiry-company" name="company" type="text" required maxlength="255" value="{{ old('company') }}" placeholder="{{ $companyPlaceholder ?? 'Acme Inc.' }}" class="{{ $fieldFor('company') }}" {!! $invalid('company') !!}>
                                    @error('company') <p id="inquiry-company-error" role="alert" class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div class="flex flex-col">
                                    <label for="inquiry-company_url" class="mb-2 block flex-1 text-sm font-semibold text-zinc-900">{{ $urlLabel ?? 'Company website' }} <span class="font-normal text-zinc-500">(optional)</span></label>
                                    {{-- A text field, not type="url": that type refuses a link typed
                                         without its scheme before the form can send, and the handler
                                         fills the scheme in itself. --}}
                                    <input id="inquiry-company_url" name="company_url" type="text" inputmode="url" autocomplete="url" spellcheck="false" maxlength="500" value="{{ old('company_url') }}" placeholder="https://" class="{{ $fieldFor('company_url') }}" {!! $invalid('company_url') !!}>
                                    @error('company_url') <p id="inquiry-company_url-error" role="alert" class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                <div class="flex flex-col">
                                    <label for="inquiry-role" class="mb-2 block flex-1 text-sm font-semibold text-zinc-900">{{ $roleLabel ?? 'Your role' }} <span class="font-normal text-zinc-500">(optional)</span></label>
                                    <input id="inquiry-role" name="role" type="text" maxlength="100" value="{{ old('role') }}" placeholder="{{ $rolePlaceholder ?? 'e.g. Head of Partnerships' }}" class="{{ $fieldFor('role') }}" {!! $invalid('role') !!}>
                                    @error('role') <p id="inquiry-role-error" role="alert" class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                                {{-- The same dropdown the contact form uses in place of the
                                     browser's own select: a button that opens a list, the
                                     choice written into a hidden input for the POST. The
                                     label points at the button, which is what takes focus. --}}
                                <div x-data="{ open: false, category: @js((string) old('category', '')) }" @click.outside="open = false" @keydown.escape="open = false" class="flex flex-col">
                                    <label for="inquiry-category" class="mb-2 block flex-1 text-sm font-semibold text-zinc-900">{{ $categoryLabel ?? 'Category' }} <span class="font-normal text-zinc-500">(optional)</span></label>
                                    <input type="hidden" name="category" :value="category">
                                    {{-- The list anchors to this box, not the column: a flex
                                         column places an absolute child at its own top. --}}
                                    <div class="relative">
                                    <button
                                        id="inquiry-category"
                                        type="button"
                                        @click="open = ! open"
                                        aria-haspopup="listbox"
                                        :aria-expanded="open.toString()"
                                        aria-controls="inquiry-category-list"
                                        class="{{ $fieldFor('category') }} flex items-center justify-between gap-2 text-left"
                                        {!! $invalid('category') !!}
                                    >
                                        <span x-text="category || 'Select one'" :class="category ? '' : 'text-zinc-500'" class="truncate"></span>
                                        <svg class="h-4 w-4 shrink-0 text-zinc-500 transition-transform duration-150" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                                    </button>
                                    <div
                                        id="inquiry-category-list"
                                        x-show="open"
                                        x-cloak
                                        x-transition:enter="transition ease-out duration-150"
                                        x-transition:enter-start="opacity-0 -translate-y-1"
                                        x-transition:enter-end="opacity-100 translate-y-0"
                                        class="absolute left-0 right-0 top-full z-20 mt-1.5 max-h-60 overflow-y-auto rounded-[12px] bg-white p-1.5 shadow-xl shadow-zinc-900/10 ring-1 ring-zinc-200 dark:ring-white/10 dark:bg-[#1d3252]"
                                        role="listbox"
                                        aria-labelledby="inquiry-category"
                                    >
                                        @foreach ($categories as $cat)
                                            <button
                                                type="button"
                                                @click="category = @js($cat); open = false"
                                                role="option"
                                                :aria-selected="(category === @js($cat)).toString()"
                                                class="flex w-full items-center justify-between gap-2 rounded-[8px] px-3 py-2 text-left text-sm font-medium transition-colors"
                                                :class="category === @js($cat) ? 'bg-blue-600/10 text-blue-700 dark:bg-blue-600/15! dark:text-zinc-200!' : 'text-zinc-700 hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-[#26416b]'"
                                            >
                                                <span>{{ $cat }}</span>
                                                <svg x-show="category === @js($cat)" class="h-4 w-4 shrink-0 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                            </button>
                                        @endforeach
                                    </div>
                                    </div>
                                    @error('category') <p id="inquiry-category-error" role="alert" class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                            </div>

                            <div>
                                <label for="inquiry-message" class="mb-2 block flex-1 text-sm font-semibold text-zinc-900">Message <span class="text-zinc-500" aria-hidden="true">*</span></label>
                                <textarea id="inquiry-message" name="message" rows="6" required minlength="10" maxlength="5000" @if ($errors->has('message')) aria-invalid="true" @endif aria-describedby="inquiry-message-hint{{ $errors->has('message') ? ' inquiry-message-error' : '' }}" placeholder="{{ $messagePlaceholder ?? 'Tell us about your proposal' }}" class="{{ $fieldFor('message', true) }} resize-y">{{ old('message') }}</textarea>
                                @error('message')
                                    <p id="inquiry-message-error" role="alert" class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                @else
                                    <p id="inquiry-message-hint" class="mt-1 text-xs text-zinc-500">Between 10 and 5,000 characters.</p>
                                @enderror
                            </div>

                            <x-turnstile-widget action="inquiry" context="contact" />

                            <p class="text-xs text-zinc-500">* Required fields</p>

                            {{-- Grey until every required field is filled in and well
                                 formed, then blue. Without JavaScript the button is
                                 simply enabled: the disabled state is bound, not
                                 written into the markup. --}}
                            <div class="pt-1">
                                <button
                                    type="submit"
                                    :disabled="sending || ! valid"
                                    :aria-busy="sending.toString()"
                                    class="inline-flex items-center justify-center gap-2 rounded-[12px] bg-blue-600 px-6 py-3 text-sm font-semibold text-white shadow-sm shadow-blue-600/25 transition-colors hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-zinc-200 disabled:text-zinc-500 disabled:shadow-none dark:disabled:bg-white/10 dark:disabled:text-zinc-400"
                                >
                                    <span x-show="! sending">{{ $submitLabel ?? 'Submit inquiry' }}</span>
                                    <span x-show="sending" x-cloak class="inline-flex items-center gap-2">
                                        <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                            <circle class="opacity-30" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3"/>
                                            <path class="opacity-90" fill="currentColor" d="M12 3a9 9 0 0 1 9 9h-3a6 6 0 0 0-6-6V3z"/>
                                        </svg>
                                        Sending
                                    </span>
                                </button>
                            </div>

                            <p class="text-xs leading-relaxed text-zinc-500">
                                By submitting this form you agree to {{ $siteName ?? config('app.name') }} using these details to review your inquiry and contact you about it. You can withdraw at any time by contacting us. See our <a href="{{ route('shop.privacy') }}" wire:navigate class="font-semibold text-blue-700 hover:text-blue-800">Privacy Policy</a> for details.
                            </p>
                        </form>
                    @endif
                </div>
            </div>

        </div>
        </div>
