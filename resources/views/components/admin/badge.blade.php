{{--
    Canonical badge for the admin surface. ONE component, six tones, used by
    every status / type chip across the system so the dashboard, list pages,
    detail pages, content CMS and rates pages never disagree on what
    "PENDING" or "PAID" looks like.

    Visual reference: the delivery badges on the customer dashboard
    (resources/views/livewire/dashboard/esims.blade.php) - a soft tint pill,
    no ring, sentence case. Every tone shares that shape and weight; only the
    hue changes, so a red badge and a green one differ in colour alone.

      <x-admin.badge tone="emerald">Active</x-admin.badge>
      <x-admin.badge tone="amber">Pending</x-admin.badge>
      <x-admin.badge tone="red">Banned</x-admin.badge>
      <x-admin.badge tone="blue">Payment - Flutterwave</x-admin.badge>
      <x-admin.badge tone="purple">Wallet - Credit</x-admin.badge>
      <x-admin.badge tone="zinc">Inactive</x-admin.badge>

    The default `tone` is `zinc` so a typo lands on the neutral look instead of
    silently rendering an untoned chip.
--}}
@props([
    'tone' => 'zinc',
])

@php
    // One shape and weight for every badge; only the hue changes. The rings
    // are gone - the tint alone carries the status, the same way the customer
    // dashboard's delivery badges read.
    $palette = [
        'emerald' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
        'amber'   => 'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
        'red'     => 'bg-red-50 text-red-700 dark:bg-red-500/15 dark:text-red-300',
        // blue-50 and blue-300 are inside the palette override's neutralised
        // range (50-400), so they paint grey however they are written. This tone
        // therefore uses a REAL blue for the tint (600) and says plainly that the
        // dark text is neutral, rather than writing blue-300 and getting grey.
        'blue'    => 'bg-blue-600/10 text-blue-700 dark:bg-blue-600/15! dark:text-zinc-200!',
        'purple'  => 'bg-purple-50 text-purple-700 dark:bg-purple-500/15 dark:text-purple-300',
        'zinc'    => 'bg-zinc-100 text-zinc-700 dark:bg-zinc-500/15! dark:text-zinc-300!',
    ];
    $toneClasses = $palette[$tone] ?? $palette['zinc'];
@endphp

<span {{ $attributes->class([
    'inline-flex w-fit items-center whitespace-nowrap rounded-full px-2.5 py-0.5 text-[11px] font-semibold',
    $toneClasses,
]) }}>{{ $slot }}</span>
