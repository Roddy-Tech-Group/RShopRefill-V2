{{--
    The panel that opens under "Gift Cards" in the nav.

    Three columns, matching how someone actually arrives: a shortlist of ways
    in, the full category list, and the brands that sell in their country.

    Everything comes from App\Support\GiftCardCategories - the same call the
    listing page, its sidebar, the mobile sheet and the home row make - so the
    menu can never list a category the page does not have.

    Hover opens it, but focus does too and Escape closes it: a menu that only
    responds to a pointer is unusable from a keyboard, and this one holds the
    whole catalogue's navigation.

    Desktop only - a hover panel has nothing to hover on a phone. There the
    menu sheet opens the same catalogue as a drill-down, fed by the same
    GiftCardCategories::navMenu() call, so the two can never disagree.
--}}
@php
    use App\Models\Product;
    use App\Support\GiftCardCategories;

    // All data through the one shared call - the phone sheet's drill-down
    // renders the same catalogue from it, so the two menus cannot drift.
    // $region is the customer's SELECTED country, shared to every view by
    // ResolveRegion - a ?country= switch first, then the region cookie they
    // last chose, then US. Re-deriving it here would duplicate middleware
    // that already runs on every request.
    [
        'categories' => $menuCategories,
        'popular' => $popular,
        'regionName' => $regionName,
    ] = GiftCardCategories::navMenu($region ?? null);

    $columnClass = 'text-[15px] font-semibold text-zinc-900 transition-colors hover:underline hover:decoration-1 hover:underline-offset-4 dark:text-zinc-100 dark:hover:text-white';
    $headingClass = 'text-[13px] font-bold uppercase tracking-[0.08em] text-[#0c1a36] dark:text-blue-300';
@endphp

{{-- Below lg: the tab on its own. The panel is desktop-only, but the tab is
     not - it is how anyone on a phone reaches gift cards. --}}
<div class="lg:hidden">
    {{ $trigger }}
</div>

@if ($menuCategories->isNotEmpty())
    <div
        class="relative hidden lg:block"
        x-data="{
            open: false,
            top: 0,

            /*
             * The rail this trigger sits in scrolls horizontally, so it clips
             * anything absolutely positioned inside it. The panel is fixed to
             * the viewport instead and positioned from the trigger's own rect,
             * which no ancestor can clip.
             */
            place() {
                // Only the vertical anchor is needed - the panel spans the full
                // viewport width, so there is no left edge to compute or keep
                // on screen.
                // Flush against the bar - any gap would show the page through
                // it and put the seam back.
                this.top = Math.round(this.$el.getBoundingClientRect().bottom);
            },
        }"
        @mouseenter="place(); open = true"
        @mouseleave="open = false"
        @focusin="place(); open = true"
        @focusout="if (! $el.contains($event.relatedTarget)) open = false"
        @keydown.escape.window="open = false"
    >
        {{ $trigger }}

        <div
            x-show="open"
            x-cloak
            x-transition:enter="transition duration-200 ease-[cubic-bezier(0.16,1,0.3,1)]"
            x-transition:enter-start="opacity-0 -translate-y-2"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition duration-150 ease-in"
            x-transition:leave-start="opacity-100 translate-y-0"
            x-transition:leave-end="opacity-0 -translate-y-2"
            :style="'top: ' + top + 'px'"
            @resize.window="place()"
            class="fixed inset-x-0 z-[60] bg-[#ffffff] py-8 shadow-xl shadow-zinc-900/10 dark:bg-[#0c1a36]"
            role="menu"
            aria-label="{{ __('navigation.gift_cards') }}"
        >
            <div class="mx-auto grid w-full max-w-[1350px] grid-cols-[220px_1fr_240px] gap-10 px-4 sm:px-6 lg:px-8">

                {{-- Ways in, before any category: most visitors want everything
                     or the popular ones, not a taxonomy. --}}
                <div>
                    <p class="{{ $headingClass }}">{{ __('navigation.featured') }}</p>
                    <ul class="mt-4 space-y-3">
                        <li>
                            <a href="{{ route('shop.gift-cards') }}" wire:navigate class="text-[25.6px] font-bold leading-[31.5px] tracking-tight text-zinc-900 transition-colors hover:text-blue-600 hover:underline hover:decoration-1 hover:underline-offset-4 dark:text-white dark:hover:text-blue-400">
                                {{ __('products.all_gift_cards') }}
                            </a>
                        </li>
                        <li>
                            <a href="{{ route('shop.gift-cards', ['subcategory' => 'popular']) }}" wire:navigate class="text-[25.6px] font-bold leading-[31.5px] tracking-tight text-zinc-900 transition-colors hover:text-blue-600 hover:underline hover:decoration-1 hover:underline-offset-4 dark:text-white dark:hover:text-blue-400">
                                {{ __('products.popular_brands') }}
                            </a>
                        </li>
                        <li>
                            <a href="{{ route('shop.gift-cards', ['sort' => 'recent']) }}" wire:navigate class="text-[25.6px] font-bold leading-[31.5px] tracking-tight text-zinc-900 transition-colors hover:text-blue-600 hover:underline hover:decoration-1 hover:underline-offset-4 dark:text-white dark:hover:text-blue-400">
                                {{ __('products.recently_added') }}
                            </a>
                        </li>
                    </ul>
                </div>

                {{-- Every category the listing has, in the same order. Three
                     balanced columns so the list reads down, not across. --}}
                <div class="border-l border-[#dee3e1] pl-8 dark:border-white/10">
                    <p class="{{ $headingClass }}">{{ __('navigation.categories') }}</p>
                    <ul class="mt-4 grid grid-cols-3 gap-x-6 gap-y-3">
                        @foreach ($menuCategories as $category)
                            <li>
                                <a href="{{ route('shop.gift-cards', ['subcategory' => $category['slug']]) }}" wire:navigate class="{{ $columnClass }}">
                                    {{ $category['name'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>

                @if ($popular->isNotEmpty())
                    <div class="border-l border-[#dee3e1] pl-8 dark:border-white/10">
                        <p class="{{ $headingClass }}">
                            {{ __('navigation.popular_in', ['country' => $regionName]) }}
                        </p>
                        <ul class="mt-4 space-y-3">
                            @foreach ($popular as $brandKey)
                                <li>
                                    <a href="{{ route('shop.brand', ['brandSlug' => Product::brandSlug($brandKey)]) }}" wire:navigate class="{{ $columnClass }}">
                                        {{ Product::brandDisplayName($brandKey) }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

            </div>
        </div>
    </div>
@else
    <div class="hidden lg:block">
        {{ $trigger }}
    </div>
@endif
