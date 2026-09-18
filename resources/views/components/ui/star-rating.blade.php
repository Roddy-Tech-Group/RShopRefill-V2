{{--
    A five star rating drawn to the fraction: 4.6 is four stars and a bit, not
    five. The row is painted twice, once faint and once solid, and the solid
    layer is clipped to the score, so any value lands where it should without
    needing half-star artwork.

    The colour is the accent by value rather than by class: the app lightens
    .text-blue-600 to near white on dark so blue links stay legible there, and a
    rating is not a link.

    Props:
      rating - the score out of five.
      size   - the star's size utilities. Defaults to the review list's.
      label  - screen reader text. Given one, the row announces itself; without
               one it is hidden, for places that already say the score in words.
--}}
@props([
    'rating' => 0,
    'size' => 'h-4 w-4',
    'label' => null,
])

@php
    $starFill = max(0.0, min(100.0, ((float) $rating / 5) * 100));
    $starPath = 'M12 .587l3.668 7.568L24 9.423l-6 5.951L19.336 24 12 19.897 4.664 24 6 15.374 0 9.423l8.332-1.268z';
@endphp

<span
    {{ $attributes->class('relative inline-flex shrink-0') }}
    @if ($label) role="img" aria-label="{{ $label }}" @else aria-hidden="true" @endif
>
    <span class="flex gap-0.5">
        @for ($star = 0; $star < 5; $star++)
            <svg class="{{ $size }} text-[#2563eb]/25" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="{{ $starPath }}"/>
            </svg>
        @endfor
    </span>

    {{-- The solid row, clipped to the score. w-max keeps it at its natural
         width inside a narrower box, so the cut falls mid-star. --}}
    <span class="pointer-events-none absolute inset-y-0 left-0 overflow-hidden" style="width: {{ round($starFill, 2) }}%">
        <span class="flex w-max gap-0.5">
            @for ($star = 0; $star < 5; $star++)
                <svg class="{{ $size }} text-[#2563eb]" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="{{ $starPath }}"/>
                </svg>
            @endfor
        </span>
    </span>
</span>
