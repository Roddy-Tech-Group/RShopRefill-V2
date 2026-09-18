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
    $field = 'w-full rounded-[12px] border border-zinc-300 bg-white px-3.5 py-2.5 text-sm text-zinc-900 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15';
@endphp

        <div class="grid grid-cols-1 gap-8 lg:grid-cols-2 lg:gap-10">

            {{-- Intro / pitch --}}
            <div>
                <div class="prose max-w-none">
                    <p class="text-base leading-relaxed text-zinc-700">{{ $intro }}</p>
                </div>

                {{-- Optional "How it works" ladder. Numbered rather than bulleted
                     because these are stages in order, and the first is the one
                     the form itself completes. --}}
                @if (! empty($steps ?? []))
                    <h2 class="mt-8 text-lg font-bold text-zinc-900 dark:text-white">How it works</h2>
                    <ol class="mt-3 space-y-2.5">
                        @foreach ($steps as $i => $step)
                            <li class="flex items-center gap-3">
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-[8px] {{ $i === 0 ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' : 'bg-transparent text-zinc-600 ring-1 ring-zinc-300 dark:text-zinc-300 dark:ring-white/20' }} text-xs font-bold">{{ $i + 1 }}</span>
                                <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ $step }}</span>
                            </li>
                        @endforeach
                    </ol>
                @endif

                {{-- Optional reassurance list: what this costs the person filling
                     the form in, which is nothing. --}}
                @if (! empty($benefits ?? []))
                    <ul class="mt-6 space-y-2.5 rounded-[12px] bg-zinc-50 p-5 dark:bg-white/5">
                        @foreach ($benefits as $benefit)
                            <li class="flex items-center gap-3">
                                <svg class="h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ $benefit }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <a href="{{ route('shop.contact') }}" wire:navigate class="mt-6 inline-flex items-center gap-2 text-sm font-semibold text-blue-700 hover:text-blue-800">
                    Or visit the general contact page
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
                </a>
            </div>

            {{-- Form --}}
            <div>
                <div class="rounded-[24px] bg-white p-6 shadow-sm shadow-zinc-900/5 ring-1 ring-zinc-100 sm:p-8">
                    @if (session('contact_sent'))
                        <div class="flex flex-col items-center py-10 text-center">
                            <span class="flex h-14 w-14 items-center justify-center rounded-[12px] bg-emerald-100">
                                <svg class="h-7 w-7 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                            </span>
                            <h2 class="mt-4 text-xl font-bold text-zinc-900">Inquiry received</h2>
                            <p class="mt-1.5 max-w-sm text-sm leading-relaxed text-zinc-600">Thanks for reaching out. Our team will review your inquiry and get back to you shortly.</p>
                            <a href="{{ url()->current() }}" wire:navigate class="mt-6 inline-flex items-center gap-2 rounded-[12px] bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-blue-700">Submit another</a>
                        </div>
                    @else
                        <div class="mb-6">
                            <h2 class="text-lg font-bold text-zinc-900">{{ $title }} inquiry</h2>
                            <p class="mt-0.5 text-sm text-zinc-600">Fields marked with an asterisk are required.</p>
                        </div>

                        <form method="POST" action="{{ route($postRoute) }}" class="space-y-5">
                            @csrf

                            {{-- Honeypot --}}
                            <div class="hidden" aria-hidden="true">
                                <label>Website<input type="text" name="website_hp" tabindex="-1" autocomplete="off"></label>
                            </div>

                            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                <div>
                                    <label for="name" class="mb-1.5 block text-sm font-medium text-zinc-700">Your name <span class="text-blue-600">*</span></label>
                                    <input id="name" name="name" type="text" required value="{{ old('name', auth()->user()?->name) }}" placeholder="Your name" class="{{ $field }}">
                                    @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="email" class="mb-1.5 block text-sm font-medium text-zinc-700">{{ $emailLabel ?? 'Work email' }} <span class="text-blue-600">*</span></label>
                                    <input id="email" name="email" type="email" required value="{{ old('email', auth()->user()?->email) }}" placeholder="{{ $emailPlaceholder ?? 'you@company.com' }}" class="{{ $field }}">
                                    @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                <div>
                                    <label for="company" class="mb-1.5 block text-sm font-medium text-zinc-700">{{ $companyLabel ?? 'Company' }} <span class="text-blue-600">*</span></label>
                                    <input id="company" name="company" type="text" required value="{{ old('company') }}" placeholder="{{ $companyPlaceholder ?? 'Acme Inc.' }}" class="{{ $field }}">
                                    @error('company') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="company_url" class="mb-1.5 block text-sm font-medium text-zinc-700">{{ $urlLabel ?? 'Company website' }} <span class="font-normal text-zinc-500">(optional)</span></label>
                                    <input id="company_url" name="company_url" type="url" value="{{ old('company_url') }}" placeholder="https://" class="{{ $field }}">
                                    @error('company_url') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                <div>
                                    <label for="role" class="mb-1.5 block text-sm font-medium text-zinc-700">{{ $roleLabel ?? 'Your role' }} <span class="font-normal text-zinc-500">(optional)</span></label>
                                    <input id="role" name="role" type="text" value="{{ old('role') }}" placeholder="{{ $rolePlaceholder ?? 'e.g. Head of Partnerships' }}" class="{{ $field }}">
                                    @error('role') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="category" class="mb-1.5 block text-sm font-medium text-zinc-700">{{ $categoryLabel ?? 'Category' }}</label>
                                    <select id="category" name="category" class="{{ $field }}">
                                        <option value="">Select one</option>
                                        @foreach ($categories as $cat)
                                            <option value="{{ $cat }}" @selected(old('category') === $cat)>{{ $cat }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <div>
                                <label for="message" class="mb-1.5 block text-sm font-medium text-zinc-700">Message <span class="text-blue-600">*</span></label>
                                <textarea id="message" name="message" rows="6" required placeholder="{{ $messagePlaceholder ?? 'Tell us about your proposal' }}" class="{{ $field }} resize-y">{{ old('message') }}</textarea>
                                @error('message') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>

                            <x-turnstile-widget action="inquiry" context="contact" />

                            <div class="flex items-center gap-3 pt-1">
                                <button type="submit" class="inline-flex items-center gap-2 rounded-[12px] bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm shadow-blue-600/25 transition-colors hover:bg-blue-700">
                                    Submit inquiry
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5"/></svg>
                                </button>
                                <p class="text-xs text-zinc-500">We never share your details.</p>
                            </div>
                        </form>
                    @endif
                </div>
            </div>

        </div>
