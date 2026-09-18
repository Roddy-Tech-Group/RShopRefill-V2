{{-- The cart glyph: a light outline drawn at the same stroke weight as the
     storefront's sign-in mark. One source for the storefront header and the
     dashboard, so the two carts can never drift apart again (the dashboard
     used the heavier filled "new cart.svg"). Inherits currentColor; size via
     the class attribute (e.g. class="h-5 w-5"). --}}
<svg {{ $attributes->merge(['class' => 'shrink-0']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <path d="M2.5 3.5h1.8a1 1 0 0 1 .97.76L6 8m0 0 1.7 6.4a1.5 1.5 0 0 0 1.45 1.1h7.3a1.5 1.5 0 0 0 1.45-1.1L20 8H6Z" />
    <circle cx="9.5" cy="19.5" r="1.5" />
    <circle cx="17" cy="19.5" r="1.5" />
</svg>
