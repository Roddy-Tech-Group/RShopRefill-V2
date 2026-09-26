{{-- The values of a gift card, as tiles.

     Its own file because it is rendered in two places and must be the same
     thing in both: inline beside the art, and inside the sheet a phone opens
     when a card carries more values than fit on a screen. One copy, so the
     tile a customer taps in the sheet is the tile they would have tapped on a
     desktop, and a change to either reaches both.

     Choosing closes the sheet. On a desktop there is no sheet and the flag it
     clears is already false, so the same handler serves both. --}}
    <div class="grid grid-cols-4 gap-2" role="radiogroup" aria-label="{{ __('products.detail_amount') }}">
        @php
            // Smallest first, and no tile is singled out: only the customer's
            // own choice is marked.
            $denomTiles = $fixedDenoms->sortBy(fn ($denom) => (float) ($denom->face_value ?? $denom->retail_price))->values();
        @endphp
        @foreach ($denomTiles as $v)
            @php
                $v->setRelation('product', $product);
                $face = (float) ($v->face_value ?? $v->retail_price);
                // `amount` keeps the value the dropdown used to set, so
                // nothing downstream of it changes. It does not price a
                // fixed value: the variant id does.
                $disp = round((float) $v->retail_price * $displayRate, 2);

                $denomFace = rtrim(rtrim(number_format($face, 2, '.', ','), '0'), '.');
                $denomCode = strtoupper($v->currency ?: $currency);

                // The tile is a ~94px square and the label wraps, so what has
                // to fit on one line is the longest word in it - the figure.
                // Most are short ("100") and take the full size; a catalogue
                // that runs to "5,000,000" steps down rather than spilling out
                // of the tile. Measured, not guessed: 16px overflowed a 94px
                // tile by 3px at nine characters.
                $denomLongest = max(strlen($denomFace), strlen($denomCode));
                $denomTextClass = match (true) {
                    $denomLongest >= 10 => 'text-xs',
                    $denomLongest >= 8 => 'text-sm',
                    default => 'text-base',
                };
            @endphp
            <button
                type="button"
                role="radio"
                @click="amount = {{ $disp }}; selectedVariantId = {{ $v->id }}; customMode = false; denomSheet = false"
                :aria-checked="(! customMode && selectedVariantId === {{ $v->id }}).toString()"
                :class="(! customMode && selectedVariantId === {{ $v->id }}) ? 'border-blue-600 ring-1 ring-blue-600 dark:border-blue-500 dark:ring-blue-500' : 'border-zinc-200 hover:border-blue-300 dark:border-white/10 dark:hover:border-blue-400/50'"
                class="pure-card relative flex aspect-square flex-col items-center justify-center rounded-[12px] border bg-white px-2 text-center transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/50"
            >
                {{-- The chosen tile also carries a check, so the choice reads at a
                     glance and not only from the outline. --}}
                <span x-show="! customMode && selectedVariantId === {{ $v->id }}" x-cloak class="absolute -right-1.5 -top-1.5 flex h-4 w-4 items-center justify-center rounded-full bg-blue-600 text-white ring-2 ring-white dark:ring-[#1a1a1e]" aria-hidden="true">
                    <svg class="h-2.5 w-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="4"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                </span>
                {{-- The face value alone. What it costs is in the purchase card,
                     which follows the chosen tile.

                     No whitespace-nowrap: most values are short ("25 USD") and
                     sit on one line, but the catalogue runs up to "5,000,000
                     VND", which wraps onto a second line inside the square
                     rather than pushing out of it. --}}
                <span class="{{ $denomTextClass }} font-bold leading-tight tabular-nums text-zinc-900 dark:text-white">{{ $denomFace }} {{ $denomCode }}</span>
            </button>
        @endforeach
        @if ($variable)
            <button
                type="button"
                role="radio"
                @click="customMode = true; selectedVariantId = {{ $variable->id }}; amount = ''; denomSheet = false"
                :aria-checked="customMode.toString()"
                :class="customMode ? 'border-blue-600 ring-1 ring-blue-600 dark:border-blue-500 dark:ring-blue-500' : 'border-zinc-200 hover:border-blue-300 dark:border-white/10 dark:hover:border-blue-400/50'"
                class="pure-card col-span-4 flex flex-col items-center justify-center rounded-[12px] border bg-white px-2 py-3 text-center transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/50"
            >
                <span class="text-sm font-bold leading-tight text-zinc-900 dark:text-white">{{ __('products.detail_custom_amount') }}</span>
                <span class="mt-0.5 text-xs tabular-nums text-zinc-600 dark:text-zinc-400">{{ $customMoney($customDisplayMin) }} - {{ $customMoney($customDisplayMax) }}</span>
            </button>
        @endif
    </div>
