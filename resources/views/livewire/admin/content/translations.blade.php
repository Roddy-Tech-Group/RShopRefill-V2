<?php

use App\Models\Concerns\HasTranslations;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new
#[Layout('components.layouts.admin')]
#[Title('Translations')]
class extends Component {
    use WithPagination;

    /**
     * Every model that opts into HasTranslations, by short name. The list is
     * declared rather than discovered: a scan of app/Models would also pick up
     * anything that merely uses the trait indirectly, and this page writes
     * customer-facing copy - it should only ever offer what an editor is meant
     * to translate.
     *
     * @var array<string, class-string>
     */
    public array $models = [
        'Product' => \App\Models\Product::class,
        'BlogPost' => \App\Models\BlogPost::class,
        'PressArticle' => \App\Models\PressArticle::class,
        'Faq' => \App\Models\Faq::class,
        'HeroCard' => \App\Models\HeroCard::class,
        'HeroSection' => \App\Models\HeroSection::class,
        'Category' => \App\Models\Category::class,
        'Subcategory' => \App\Models\Subcategory::class,
    ];

    public string $model = 'BlogPost';

    /** The locale being edited. Never the default - that one IS the column. */
    public string $locale = '';

    public string $search = '';

    public ?int $editingId = null;

    /** @var array<string, string> field => translated value, while editing */
    public array $draft = [];

    public function mount(): void
    {
        $this->locale = (string) array_key_first($this->targetLocales());
    }

    /**
     * Languages that can be translated INTO: everything except the default,
     * which is whatever the source columns already hold.
     *
     * @return array<string, string>
     */
    public function targetLocales(): array
    {
        $default = config('localization.default', 'en');

        return collect(config('localization.supported', []))
            ->reject(fn ($_, $code) => $code === $default)
            ->mapWithKeys(fn ($meta, $code) => [$code => $meta['native_name'] ?? $meta['name'] ?? $code])
            ->all();
    }

    /** @return class-string */
    private function modelClass(): string
    {
        return $this->models[$this->model] ?? \App\Models\BlogPost::class;
    }

    /**
     * The label column to list records by - the first translatable field that
     * looks like a title, else the first one.
     */
    private function labelField(string $class): string
    {
        $fields = (new $class)->translatableFields();

        foreach (['name', 'title', 'question', 'topic'] as $preferred) {
            if (in_array($preferred, $fields, true)) {
                return $preferred;
            }
        }

        return $fields[0] ?? 'id';
    }

    #[Computed]
    public function fields(): array
    {
        return (new ($this->modelClass()))->translatableFields();
    }

    #[Computed]
    public function records()
    {
        $class = $this->modelClass();
        $label = $this->labelField($class);

        return $class::query()
            ->when($this->search !== '', fn ($q) => $q->where($label, 'like', '%'.$this->search.'%'))
            ->withTranslations($this->locale)
            ->orderBy($label)
            ->paginate(15);
    }

    public function updatedModel(): void
    {
        $this->reset('editingId', 'draft', 'search');
        $this->resetPage();
    }

    public function updatedLocale(): void
    {
        $this->reset('editingId', 'draft');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /** Open one record, seeded with whatever is already translated. */
    public function edit(int $id): void
    {
        $record = $this->modelClass()::findOrFail($id);

        $this->editingId = $id;
        $this->draft = collect($this->fields())
            ->mapWithKeys(fn ($field) => [$field => (string) ($record->translate($field, $this->locale) ?? '')])
            ->all();
    }

    public function cancel(): void
    {
        $this->reset('editingId', 'draft');
    }

    public function save(): void
    {
        abort_unless(array_key_exists($this->locale, $this->targetLocales()), 422);

        $record = $this->modelClass()::findOrFail($this->editingId);

        foreach ($this->fields() as $field) {
            // A blank box means "use the source copy" - setTranslation deletes
            // the row rather than storing an empty string.
            $record->setTranslation($field, $this->locale, $this->draft[$field] ?? null);
        }

        $this->reset('editingId', 'draft');
        unset($this->records);

        $this->dispatch('toast', message: 'Translations saved.');
    }

    /** The heading for one row: its title-ish source text, else its id. */
    public function recordLabel($record): string
    {
        $label = $this->source($record, $this->labelField($this->modelClass()));

        return trim($label) !== '' ? \Illuminate\Support\Str::limit($label, 70) : '#'.$record->id;
    }

    /** Source text for a field, for the side-by-side the editor types against. */
    public function source($record, string $field): string
    {
        return (string) ($record->getRawOriginal($field) ?? '');
    }

    /** How many of this record's fields already carry copy in this locale. */
    public function translatedCount($record): int
    {
        return collect($this->fields())
            ->filter(fn ($field) => $record->translate($field, $this->locale) !== null)
            ->count();
    }
} ?>

<div class="space-y-6">
    {{-- Header --}}
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Translations</h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                Per-language copy for content that lives in the database. Anything left blank falls back to the
                original text, so a half-translated record still reads correctly.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <div>
                <label for="tr-model" class="mb-1 block text-[13px] font-medium text-zinc-600 dark:text-zinc-400">Content type</label>
                <select id="tr-model" wire:model.live="model" class="rounded-[12px] border-0 bg-white py-2.5 pl-3 pr-8 text-sm font-medium text-zinc-900 ring-1 ring-zinc-200 focus:ring-2 focus:ring-blue-500/40 dark:bg-white/5 dark:text-white dark:ring-white/10">
                    @foreach (array_keys($models) as $name)
                        <option value="{{ $name }}">{{ Str::headline($name) }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="tr-locale" class="mb-1 block text-[13px] font-medium text-zinc-600 dark:text-zinc-400">Language</label>
                <select id="tr-locale" wire:model.live="locale" class="rounded-[12px] border-0 bg-white py-2.5 pl-3 pr-8 text-sm font-medium text-zinc-900 ring-1 ring-zinc-200 focus:ring-2 focus:ring-blue-500/40 dark:bg-white/5 dark:text-white dark:ring-white/10">
                    @foreach ($this->targetLocales() as $code => $label)
                        <option value="{{ $code }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    {{-- Search --}}
    <div>
        <input
            type="text"
            wire:model.live.debounce.300ms="search"
            placeholder="Search {{ Str::headline($model) }}…"
            class="w-full max-w-md rounded-[12px] border-0 bg-white px-4 py-2.5 text-sm text-zinc-900 ring-1 ring-zinc-200 placeholder:text-zinc-500 focus:ring-2 focus:ring-blue-500/40 dark:bg-white/5 dark:text-white dark:ring-white/10"
        >
    </div>

    {{-- Records --}}
    <div class="overflow-hidden rounded-[12px] bg-white dark:bg-white/5">
        @forelse ($this->records as $record)
            @php
                $done = $this->translatedCount($record);
                $total = count($this->fields());
            @endphp

            <div class="border-b border-zinc-100 last:border-0 dark:border-white/10">
                <div class="flex items-center justify-between gap-4 px-5 py-4">
                    <div class="min-w-0">
                        <p class="truncate text-[15px] font-semibold text-zinc-900 dark:text-white">
                            {{ $this->recordLabel($record) }}
                        </p>
                        <p class="mt-0.5 text-[13px] text-zinc-600 dark:text-zinc-400">
                            {{ $done }} of {{ $total }} {{ Str::plural('field', $total) }} translated
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-3">
                        <span @class([
                            'rounded-full px-2.5 py-1 text-[12px] font-semibold',
                            'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' => $done === $total && $total > 0,
                            'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300' => $done > 0 && $done < $total,
                            'bg-zinc-100 text-zinc-600 dark:bg-white/10 dark:text-zinc-400' => $done === 0,
                        ])>
                            @if ($done === $total && $total > 0) Complete
                            @elseif ($done > 0) Partial
                            @else Not started
                            @endif
                        </span>

                        <button
                            type="button"
                            wire:click="edit({{ $record->id }})"
                            class="rounded-full bg-[#f4f6f5] px-3.5 py-1.5 text-[13px] font-semibold text-zinc-900 transition-colors hover:bg-[#e8ecea] dark:bg-white/10 dark:text-zinc-200 dark:hover:bg-white/15"
                        >
                            {{ $editingId === $record->id ? 'Editing' : 'Translate' }}
                        </button>
                    </div>
                </div>

                {{-- Editor: source on the left, the translation beside it, so the
                     editor is never typing from memory. --}}
                @if ($editingId === $record->id)
                    <div class="border-t border-zinc-100 bg-[#f4f6f5] px-5 py-5 dark:border-white/10 dark:bg-white/5">
                        <div class="space-y-5">
                            @foreach ($this->fields() as $field)
                                <div class="grid gap-3 md:grid-cols-2">
                                    <div>
                                        <p class="mb-1.5 text-[12px] font-bold uppercase tracking-[0.08em] text-zinc-500 dark:text-zinc-400">
                                            {{ Str::headline($field) }} — original
                                        </p>
                                        <div class="max-h-40 overflow-y-auto rounded-[12px] bg-white p-3 text-sm leading-relaxed text-zinc-700 dark:bg-white/5 dark:text-zinc-300">
                                            {{ $this->source($record, $field) ?: '—' }}
                                        </div>
                                    </div>

                                    <div>
                                        <label for="tr-{{ $record->id }}-{{ $field }}" class="mb-1.5 block text-[12px] font-bold uppercase tracking-[0.08em] text-[#0c1a36] dark:text-blue-300">
                                            {{ Str::headline($field) }} — {{ $this->targetLocales()[$locale] ?? $locale }}
                                        </label>
                                        <textarea
                                            id="tr-{{ $record->id }}-{{ $field }}"
                                            wire:model="draft.{{ $field }}"
                                            rows="4"
                                            placeholder="Leave blank to use the original"
                                            class="w-full rounded-[12px] border-0 bg-white p-3 text-sm leading-relaxed text-zinc-900 ring-1 ring-zinc-200 placeholder:text-zinc-500 focus:ring-2 focus:ring-blue-500/40 dark:bg-white/5 dark:text-white dark:ring-white/10"
                                        ></textarea>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="mt-5 flex items-center gap-3">
                            <button
                                type="button"
                                wire:click="save"
                                wire:loading.attr="disabled"
                                class="rounded-full bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-blue-700 disabled:opacity-60"
                            >
                                Save translations
                            </button>
                            <button
                                type="button"
                                wire:click="cancel"
                                class="rounded-full px-4 py-2.5 text-sm font-semibold text-zinc-600 transition-colors hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white"
                            >
                                Cancel
                            </button>
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <p class="px-5 py-10 text-center text-sm text-zinc-600 dark:text-zinc-400">
                Nothing to translate here yet.
            </p>
        @endforelse
    </div>

    <div>
        {{ $this->records->links() }}
    </div>
</div>
