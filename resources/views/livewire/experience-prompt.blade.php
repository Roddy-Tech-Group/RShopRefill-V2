{{-- FILE: resources/views/livewire/experience-prompt.blade.php --}}
<?php

use App\Domain\Reviews\Services\ExperiencePromptService;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

/**
 * Rate your experience. Two questions: a 1-10 score, then an optional comment
 * with the choice to stay anonymous. The page keeps a clock from the moment the
 * customer lands on the dashboard; when it passes the admin-set delay, it asks
 * the server ONCE whether this customer is due a prompt today. The server owns
 * the once-a-day rule and the stop-after-answering rule (ExperiencePromptService),
 * so a cleared browser cannot re-ask. Centred over the panel on desktop, a sheet
 * that slides up from the bottom on a phone.
 */
new class extends Component {
    public bool $open = false;

    public int $step = 1;

    public ?int $score = null;

    public string $comment = '';

    public bool $anonymous = false;

    public bool $submitted = false;

    /** The service line the question names, or null for the general question. */
    public ?string $subject = null;

    /** Seconds on the dashboard before the page asks whether to prompt. */
    public int $delaySeconds = 0;

    public function mount(ExperiencePromptService $prompt): void
    {
        $this->delaySeconds = $prompt->delayMinutes() * 60;
    }

    /** The page's clock ran out. Open only if the server says this customer is due today. */
    public function tryOpen(ExperiencePromptService $prompt): void
    {
        $user = auth()->user();

        if (! $user || ! $prompt->shouldPrompt($user)) {
            return;
        }

        $prompt->markPrompted($user);

        $this->subject = $prompt->subjectFor($user);
        $this->step = 1;
        $this->open = true;
    }

    public function choose(int $score): void
    {
        $this->score = max(ExperiencePromptService::SCORE_MIN, min(ExperiencePromptService::SCORE_MAX, $score));
        $this->step = 2;
    }

    public function back(): void
    {
        $this->step = 1;
    }

    /** Closing without answering: already marked as asked, so it returns tomorrow. */
    public function close(): void
    {
        $this->open = false;
    }

    public function submit(ExperiencePromptService $prompt): void
    {
        $this->validate([
            'score' => ['required', 'integer', 'min:'.ExperiencePromptService::SCORE_MIN, 'max:'.ExperiencePromptService::SCORE_MAX],
            'comment' => ['nullable', 'string', 'max:300'],
            'anonymous' => ['boolean'],
        ]);

        $prompt->submit(auth()->user(), (int) $this->score, $this->comment, $this->anonymous);

        $this->submitted = true;
    }

    #[Computed]
    public function question(): string
    {
        return $this->subject
            ? "How would you rate our {$this->subject}?"
            : 'How would you rate your experience?';
    }
}; ?>

<div
    x-data="{
        delay: @js($delaySeconds),
        start() {
            /* The clock is per browser tab and survives wire:navigate. A prompt
               already asked for is not asked for again in this tab: the server
               decides whether it is due, and it has already been asked once. */
            try {
                if (sessionStorage.getItem('experience.asked')) return;
                let t0 = Number(sessionStorage.getItem('experience.start'));
                if (! t0) { t0 = Date.now(); sessionStorage.setItem('experience.start', String(t0)); }
                const check = () => {
                    if (sessionStorage.getItem('experience.asked')) return;
                    if (Date.now() - t0 >= this.delay * 1000) {
                        sessionStorage.setItem('experience.asked', '1');
                        $wire.tryOpen();
                    } else {
                        setTimeout(check, 1000);
                    }
                };
                check();
            } catch (e) {}
        },
    }"
    x-init="start()"
    x-on:livewire:navigated.window="start()"
>
    @if ($open)
        <div
            x-data="{ show: false, dismiss() { this.show = false; setTimeout(() => $wire.close(), 220); } }"
            x-init="$nextTick(() => show = true)"
            @keydown.escape.window="dismiss()"
            class="fixed inset-0 z-[70]"
            role="dialog"
            aria-modal="true"
            aria-labelledby="experience-prompt-title"
        >
            <div x-show="show" x-transition.opacity.duration.200ms @click="dismiss()" class="absolute inset-0 bg-black/40"></div>

            {{-- The panel. Below lg it is a sheet pinned to the bottom edge that
                 slides up; from lg it sits centred over the dashboard panel and
                 fades in. The two enter/leave sets are split by breakpoint. --}}
            <div
                x-show="show"
                x-transition:enter="transition duration-200 ease-out"
                x-transition:enter-start="max-lg:translate-y-full lg:scale-95 lg:opacity-0"
                x-transition:enter-end="max-lg:translate-y-0 lg:scale-100 lg:opacity-100"
                x-transition:leave="transition duration-200 ease-in"
                x-transition:leave-start="max-lg:translate-y-0 lg:scale-100 lg:opacity-100"
                x-transition:leave-end="max-lg:translate-y-full lg:scale-95 lg:opacity-0"
                class="experience-prompt absolute inset-x-0 bottom-0 rounded-t-[12px] bg-white p-6 pb-[max(1.5rem,env(safe-area-inset-bottom))] shadow-2xl shadow-zinc-900/20 ring-1 ring-zinc-200 dark:bg-[#1d3252] dark:ring-white/10 lg:inset-auto lg:left-1/2 lg:top-1/2 lg:w-[min(600px,calc(100vw-2rem))] lg:-translate-x-1/2 lg:-translate-y-1/2 lg:rounded-[12px] lg:p-8"
            >
                <div class="flex items-start justify-between gap-4">
                    <h2 id="experience-prompt-title" class="text-xl font-bold leading-tight text-zinc-900 dark:text-white">{{ $this->question }}</h2>
                    <button
                        type="button"
                        @click="dismiss()"
                        aria-label="Close"
                        class="-mr-2 -mt-2 flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-zinc-500 transition-colors hover:bg-zinc-100 hover:text-zinc-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/50 dark:text-zinc-400 dark:hover:bg-white/10 dark:hover:text-white"
                    >
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                @if ($submitted)
                    <p class="mt-6 text-sm leading-relaxed text-zinc-700 dark:text-zinc-300">Thank you. Your feedback will appear once our team has reviewed it.</p>
                    <button type="button" @click="dismiss()" class="mt-6 flex w-full items-center justify-center rounded-[12px] bg-blue-600 px-4 py-3 text-sm font-bold text-white transition-colors hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/50">
                        Done
                    </button>
                @elseif ($step === 1)
                    {{-- Ten circles; the chosen one fills brand blue. --}}
                    <div class="mt-6 flex justify-between gap-0.5 sm:gap-1" role="radiogroup" aria-label="Score from 1 to 10">
                        @for ($i = ExperiencePromptService::SCORE_MIN; $i <= ExperiencePromptService::SCORE_MAX; $i++)
                            <button
                                type="button"
                                wire:click="choose({{ $i }})"
                                role="radio"
                                aria-checked="{{ $score === $i ? 'true' : 'false' }}"
                                class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full border-2 text-[11px] font-semibold transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/50 sm:h-10 sm:w-10 sm:text-sm {{ $score === $i
                                    ? 'border-blue-600 bg-blue-600 text-white'
                                    : 'border-zinc-300 text-zinc-700 hover:border-blue-600 hover:text-blue-600 dark:border-white/20 dark:text-zinc-200 dark:hover:border-blue-400 dark:hover:text-blue-400' }}"
                            >{{ $i }}</button>
                        @endfor
                    </div>
                    <div class="mt-2 flex justify-between text-sm text-zinc-500 dark:text-zinc-400">
                        <span>Poor</span>
                        <span>Excellent</span>
                    </div>
                    <p class="mt-6 text-sm text-zinc-500 dark:text-zinc-400">Question 1 of 2</p>
                @else
                    <label for="experience-comment" class="mt-6 block text-sm font-semibold text-zinc-900 dark:text-white">Anything you would like to add? <span class="font-normal text-zinc-500 dark:text-zinc-400">(optional)</span></label>
                    <textarea
                        id="experience-comment"
                        wire:model="comment"
                        maxlength="300"
                        rows="4"
                        placeholder="Tell us more about your experience"
                        class="mt-2 w-full resize-none rounded-[12px] border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-900 outline-none transition-colors placeholder:text-zinc-400 focus:border-blue-600 focus:bg-white focus:ring-4 focus:ring-blue-600/15 dark:border-white/15 dark:bg-white/5 dark:text-white dark:focus:border-blue-400 dark:focus:ring-blue-400/20"
                    ></textarea>
                    <div class="mt-1 flex items-center justify-between">
                        @error('comment') <p class="text-xs font-medium text-red-600 dark:text-red-400">{{ $message }}</p> @else <span></span> @enderror
                        <span class="text-xs text-zinc-500 dark:text-zinc-400" x-text="($wire.comment || '').length + '/300'">0/300</span>
                    </div>

                    <label class="mt-4 flex cursor-pointer items-center gap-3 text-sm font-medium text-zinc-800 dark:text-zinc-200">
                        <input type="checkbox" wire:model="anonymous" class="h-4 w-4 rounded-[4px] border-zinc-300 text-blue-600 focus:ring-blue-500/40 dark:border-white/20 dark:bg-white/5">
                        Submit anonymously
                    </label>

                    <div class="mt-6 grid grid-cols-2 gap-3">
                        <button type="button" wire:click="back" class="flex items-center justify-center rounded-[12px] bg-zinc-100 px-4 py-3 text-sm font-semibold text-zinc-800 transition-colors hover:bg-zinc-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/50 dark:bg-white/10 dark:text-zinc-200 dark:hover:bg-white/15">
                            Back
                        </button>
                        <button type="button" wire:click="submit" wire:loading.attr="disabled" class="flex items-center justify-center rounded-[12px] bg-blue-600 px-4 py-3 text-sm font-bold text-white transition-colors hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/50 disabled:cursor-not-allowed disabled:opacity-50">
                            <span wire:loading.remove wire:target="submit">Submit</span>
                            <span wire:loading wire:target="submit">Sending...</span>
                        </button>
                    </div>
                    <p class="mt-4 text-sm text-zinc-500 dark:text-zinc-400">Question 2 of 2</p>
                @endif
            </div>
        </div>
    @endif
</div>
