@props([
    'flag' => null,
    'title' => 'Temporarily paused',
    'message' => 'This action is currently unavailable. Please check back shortly.',
])

{{-- Amber banner shown when a kill-switch feature flag is off, so users see
     the paused state upfront instead of bouncing off a submit error. Pass
     `flag` to gate on a feature flag (auto-hides when flag is on); pass just
     `title` + `message` to render unconditionally. --}}
@php
    $visible = $flag === null
        ? true
        : ! \App\Support\FeatureFlag::on($flag);
@endphp

@if ($visible)
    <div {{ $attributes->class([
        'flex items-start gap-3 rounded-[12px] border border-amber-300 bg-amber-50 px-4 py-3 text-amber-900',
        'dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200',
    ]) }} role="status" aria-live="polite">
        <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.732 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
        </svg>
        <div class="min-w-0 flex-1">
            <p class="text-sm font-bold">{{ $title }}</p>
            <p class="mt-0.5 text-xs leading-relaxed">{{ $message }}</p>
        </div>
    </div>
@endif
