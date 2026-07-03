{{-- Shared inquiry-form layout for Partnerships + Suppliers. The two pages
     differ only in copy, category dropdown contents, and the POST action; this
     partial holds the markup so a copy tweak ships in one place.

     Expected vars (passed via @include):
       $kind        : 'partnership' | 'supplier'
       $title       : page H1 ("Partnerships", "Suppliers")
       $tagline     : hero subhead
       $intro       : intro paragraph next to the form
       $postRoute   : route name for POST
       $categories  : array<string> of options for the category select
--}}
<x-layouts.app.header :title="$title.' | '.$siteName">

    @php
        $field = 'w-full rounded-[12px] border border-zinc-300 bg-white px-3.5 py-2.5 text-sm text-zinc-900 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15';
    @endphp

    {{-- Hero --}}
    <section class="border-b border-zinc-100 bg-blue-50">
        <div class="mx-auto w-full max-w-[1000px] px-4 py-14 text-center sm:px-6 sm:py-20">
            <span class="inline-flex items-center gap-2 rounded-[5px] bg-blue-100 px-3 py-1.5 text-[11px] font-bold uppercase tracking-[0.14em] text-blue-700">{{ strtoupper($kind) }}</span>
            <h1 class="mt-5 text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl lg:text-5xl">{{ $title }}</h1>
            <p class="mx-auto mt-3 max-w-xl text-sm leading-relaxed text-zinc-600 sm:text-base">{{ $tagline }}</p>
        </div>
    </section>

    <section class="mx-auto w-full max-w-[1140px] px-4 py-12 sm:px-6 sm:py-16">
        <div class="grid grid-cols-1 gap-8 lg:grid-cols-2 lg:gap-10">

            {{-- Intro / pitch --}}
            <div>
                <div class="prose max-w-none">
                    <p class="text-base leading-relaxed text-zinc-700">{{ $intro }}</p>
                </div>

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
                                    <label for="email" class="mb-1.5 block text-sm font-medium text-zinc-700">Work email <span class="text-blue-600">*</span></label>
                                    <input id="email" name="email" type="email" required value="{{ old('email', auth()->user()?->email) }}" placeholder="you@company.com" class="{{ $field }}">
                                    @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                <div>
                                    <label for="company" class="mb-1.5 block text-sm font-medium text-zinc-700">Company <span class="text-blue-600">*</span></label>
                                    <input id="company" name="company" type="text" required value="{{ old('company') }}" placeholder="Acme Inc." class="{{ $field }}">
                                    @error('company') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="company_url" class="mb-1.5 block text-sm font-medium text-zinc-700">Company website <span class="font-normal text-zinc-500">(optional)</span></label>
                                    <input id="company_url" name="company_url" type="url" value="{{ old('company_url') }}" placeholder="https://" class="{{ $field }}">
                                    @error('company_url') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                <div>
                                    <label for="role" class="mb-1.5 block text-sm font-medium text-zinc-700">Your role <span class="font-normal text-zinc-500">(optional)</span></label>
                                    <input id="role" name="role" type="text" value="{{ old('role') }}" placeholder="e.g. Head of Partnerships" class="{{ $field }}">
                                    @error('role') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="category" class="mb-1.5 block text-sm font-medium text-zinc-700">Category</label>
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
                                <textarea id="message" name="message" rows="6" required placeholder="Tell us about your proposal" class="{{ $field }} resize-y">{{ old('message') }}</textarea>
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
    </section>

</x-layouts.app.header>
