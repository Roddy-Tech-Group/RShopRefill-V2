<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.admin')]
#[Title('Runbooks')]
class extends Component {
    public ?string $runbook = null;

    /** Curated incident-likelihood ordering; unknown files append after. */
    private const ORDER = [
        'payment-provider-failure',
        'fulfillment-provider-outage',
        'queue-backlog',
        'ledger-integrity',
        'database-restore',
        'deployment-rollback',
        'fx-failure',
        'pricing-anomaly',
        'traffic-spike',
        'fraud-attack',
        'security-breach',
    ];

    public function mount(?string $runbook = null): void
    {
        $this->runbook = $runbook;

        if ($this->runbook === null) {
            return;
        }

        $entry = $this->library()[$this->runbook] ?? null;

        if ($entry === null) {
            abort(404);
        }

        // HTML reference docs are served as authored, not rendered through the
        // markdown prose - send a direct visit over to the doc route.
        if ($entry['type'] === 'doc') {
            $this->redirectRoute('admin.management.runbooks.doc', $this->runbook);
        }
    }

    /**
     * Everything on disk, keyed by slug: markdown runbooks (type 'runbook',
     * rendered in-page) and self-contained HTML reference docs like the Fraud
     * & Risk Engine handbook (type 'doc', opened as authored). The slug
     * whitelist doubles as the path-traversal guard: only files actually
     * inside docs/runbooks are reachable.
     *
     * @return array<string, array{slug: string, title: string, summary: string, type: string}>
     */
    public function library(): array
    {
        // A deploy that somehow misses docs/ must degrade to an empty library,
        // not take down the page an operator opens mid-incident.
        if (! File::isDirectory(base_path('docs/runbooks'))) {
            return [];
        }

        $files = collect(File::files(base_path('docs/runbooks')))
            ->filter(fn ($file) => in_array(strtolower($file->getExtension()), ['md', 'html'], true));

        $entries = [];
        foreach ($files as $file) {
            $slug = $file->getFilenameWithoutExtension();

            if (strtolower($file->getExtension()) === 'html') {
                $head = (string) File::get($file->getPathname());
                $title = preg_match('/<title>(.*?)<\/title>/is', $head, $m)
                    ? trim(html_entity_decode($m[1]))
                    : $slug;
                // Drop a leading site-name prefix ("RshopRefill — ...").
                $title = Str::of($title)->afterLast('— ')->trim()->toString();

                $entries[$slug] = ['slug' => $slug, 'title' => $title, 'summary' => 'Reference handbook, opens as its own document.', 'type' => 'doc'];

                continue;
            }

            $firstLines = array_slice(preg_split('/\r?\n/', (string) File::get($file->getPathname())), 0, 10);

            $title = Str::of(collect($firstLines)->first(fn ($line) => str_starts_with($line, '# ')) ?? $slug)
                ->after('# ')->after('Runbook · ')->toString();

            $severityLine = collect($firstLines)->first(fn ($line) => str_contains($line, '**Severity'));
            $summary = $severityLine
                ? trim(strip_tags(Str::of($severityLine)->after(':')->replace('**', '')->toString()))
                : '';

            $entries[$slug] = ['slug' => $slug, 'title' => $title, 'summary' => $summary, 'type' => 'runbook'];
        }

        // Curated order first, anything new appended alphabetically.
        $ordered = [];
        foreach (self::ORDER as $slug) {
            if (isset($entries[$slug])) {
                $ordered[$slug] = $entries[$slug];
                unset($entries[$slug]);
            }
        }
        ksort($entries);

        return $ordered + $entries;
    }

    public function with(): array
    {
        $library = $this->library();

        $html = null;
        if ($this->runbook !== null && ($library[$this->runbook]['type'] ?? null) === 'runbook') {
            $html = Str::markdown(
                (string) File::get(base_path("docs/runbooks/{$this->runbook}.md")),
                ['html_input' => 'strip', 'allow_unsafe_links' => false],
            );
        }

        return [
            'library' => $library,
            'html' => $html,
        ];
    }
}; ?>

<x-slot:heading>Runbooks</x-slot:heading>

@php
    $card = 'rounded-[12px] ring-1 ring-zinc-200 bg-white shadow-sm shadow-zinc-900/[0.04] dark:ring-white/10 dark:bg-[#1d3252]';
@endphp

<div class="max-w-7xl mx-auto -mt-2">
    <style>
        .runbook-prose { color: rgb(63 63 70); font-size: 14px; line-height: 1.7; }
        html.dark .runbook-prose { color: rgb(212 212 216); }
        .runbook-prose h1 { font-size: 1.35rem; font-weight: 800; margin: 0 0 .75rem; color: rgb(24 24 27); }
        .runbook-prose h2 { font-size: 1.05rem; font-weight: 700; margin: 1.75rem 0 .5rem; padding-top: 1rem; border-top: 1px solid rgb(228 228 231); color: rgb(24 24 27); }
        .runbook-prose h3 { font-size: .95rem; font-weight: 700; margin: 1.25rem 0 .4rem; color: rgb(24 24 27); }
        html.dark .runbook-prose h1, html.dark .runbook-prose h2, html.dark .runbook-prose h3 { color: #fff; }
        html.dark .runbook-prose h2 { border-top-color: rgba(255,255,255,.1); }
        .runbook-prose p { margin: .6rem 0; }
        .runbook-prose ul, .runbook-prose ol { margin: .6rem 0 .6rem 1.4rem; }
        .runbook-prose ul { list-style: disc; }
        .runbook-prose ol { list-style: decimal; }
        .runbook-prose li { margin: .25rem 0; }
        .runbook-prose blockquote { margin: 1rem 0; padding: .65rem 1rem; border-left: 3px solid rgb(59 130 246); background: rgb(239 246 255); border-radius: 0 10px 10px 0; font-weight: 500; }
        html.dark .runbook-prose blockquote { background: rgba(59,130,246,.1); }
        .runbook-prose code { font-size: .8rem; background: rgb(244 244 245); padding: .1rem .35rem; border-radius: 6px; }
        html.dark .runbook-prose code { background: rgba(255,255,255,.1); }
        .runbook-prose pre { margin: .75rem 0; padding: .85rem 1rem; border-radius: 10px; background: rgb(24 24 27); overflow-x: auto; }
        .runbook-prose pre code { background: transparent; color: rgb(228 228 231); padding: 0; font-size: .8rem; }
        .runbook-prose table { width: 100%; margin: .75rem 0; border-collapse: collapse; font-size: .82rem; }
        .runbook-prose th { text-align: left; font-weight: 700; padding: .45rem .6rem; border-bottom: 2px solid rgb(228 228 231); }
        .runbook-prose td { padding: .45rem .6rem; border-bottom: 1px solid rgb(240 240 242); vertical-align: top; }
        html.dark .runbook-prose th { border-bottom-color: rgba(255,255,255,.15); }
        html.dark .runbook-prose td { border-bottom-color: rgba(255,255,255,.07); }
        .runbook-prose a { color: rgb(37 99 235); font-weight: 600; }
        html.dark .runbook-prose a { color: rgb(96 165 250); }
        .runbook-prose hr { margin: 1.25rem 0; border-color: rgb(228 228 231); }
        html.dark .runbook-prose hr { border-color: rgba(255,255,255,.1); }
    </style>

    @if ($runbook === null)
        {{-- ── Index ─────────────────────────────────────────────── --}}
        <p class="mb-5 text-[13px] text-zinc-500 dark:text-zinc-400">
            During an incident you execute, you don't compose. Find the case, open it, follow it top to bottom.
            If a step is wrong or missing, fix the file in <code class="rounded bg-zinc-100 px-1 py-0.5 text-[11px] dark:bg-white/10">docs/runbooks/</code> the same day.
        </p>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($library as $entry)
                @if ($entry['type'] === 'doc')
                    <a href="{{ route('admin.management.runbooks.doc', $entry['slug']) }}" target="_blank" rel="noopener" class="{{ $card }} group block p-5 transition-shadow hover:shadow-md">
                        <div class="flex items-start justify-between gap-3">
                            <h2 class="text-sm font-bold text-zinc-900 group-hover:text-blue-700 dark:text-white dark:group-hover:text-blue-300">{{ $entry['title'] }}</h2>
                            <svg class="mt-0.5 h-4 w-4 shrink-0 text-zinc-300 transition-colors group-hover:text-blue-500 dark:text-zinc-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H18v4.5M18 6l-7.5 7.5M18 13.5V18H6V6h4.5"/></svg>
                        </div>
                        @if ($entry['summary'] !== '')
                            <p class="mt-2 text-[12px] leading-relaxed text-zinc-500 dark:text-zinc-400">{{ Str::limit($entry['summary'], 120) }}</p>
                        @endif
                    </a>
                @else
                    <a href="{{ route('admin.management.runbooks', $entry['slug']) }}" wire:navigate class="{{ $card }} group block p-5 transition-shadow hover:shadow-md">
                        <div class="flex items-start justify-between gap-3">
                            <h2 class="text-sm font-bold text-zinc-900 group-hover:text-blue-700 dark:text-white dark:group-hover:text-blue-300">{{ $entry['title'] }}</h2>
                            <svg class="mt-0.5 h-4 w-4 shrink-0 text-zinc-300 transition-colors group-hover:text-blue-500 dark:text-zinc-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                        </div>
                        @if ($entry['summary'] !== '')
                            <p class="mt-2 text-[12px] leading-relaxed text-zinc-500 dark:text-zinc-400">{{ Str::limit($entry['summary'], 120) }}</p>
                        @endif
                    </a>
                @endif
            @endforeach
        </div>
    @else
        {{-- ── Single runbook ────────────────────────────────────── --}}
        <div class="mb-4 flex items-center justify-between gap-3">
            <a href="{{ route('admin.management.runbooks') }}" wire:navigate class="inline-flex items-center gap-1.5 text-[13px] font-semibold text-blue-600 hover:text-blue-500 dark:text-blue-400">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                All runbooks
            </a>
            <p class="text-[11px] text-zinc-400">Source: <code class="rounded bg-zinc-100 px-1 py-0.5 dark:bg-white/10">docs/runbooks/{{ $runbook }}.md</code></p>
        </div>
        <article class="{{ $card }} runbook-prose p-6 sm:p-8">
            {!! $html !!}
        </article>
    @endif
</div>
