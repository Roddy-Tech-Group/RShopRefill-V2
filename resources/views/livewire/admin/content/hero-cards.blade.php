<?php

use App\Models\HeroCard;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new
#[Layout('components.layouts.admin')]
#[Title('Hero Cards')]
class extends Component {
    use WithFileUploads;

    public ?int $editingId = null;

    public bool $showForm = false;

    #[Validate('required|string|max:120')]
    public string $title = '';

    #[Validate('nullable|string|max:300')]
    public string $subtitle = '';

    /** Where the card body links to. Relative paths (/gift-cards/apple) or full URLs. */
    #[Validate('nullable|string|max:500')]
    public string $linkUrl = '';

    /** Learn more button target; empty hides the button. */
    #[Validate('nullable|string|max:500')]
    public string $learnUrl = '/help';

    /** Buy now button target; empty hides the button. */
    #[Validate('nullable|string|max:500')]
    public string $buyUrl = '';

    /** Horizontal focal point (0 = left edge, 100 = right edge) for the
     *  square crop, so landscape art keeps its subject in frame. */
    #[Validate('integer|min:0|max:100')]
    public int $focalX = 50;

    /** Existing image path (relative to public/assets/), kept when editing. */
    #[Validate('nullable|string|max:500')]
    public string $image = '';

    /** Newly uploaded card image. On save the file moves into
     *  public/assets/hero-cards/ and the relative path is stored in the DB. */
    #[Validate('nullable|file|mimes:jpg,jpeg,png,webp,gif,svg|max:5120')] // 5MB cap
    public $imageUpload = null;

    #[Validate('boolean')]
    public bool $isEnabled = true;

    #[Validate('integer|min:0|max:10000')]
    public int $sortOrder = 1;

    #[Computed]
    public function cards()
    {
        return HeroCard::orderBy('sort_order')->orderBy('id')->get();
    }

    public function newCard(): void
    {
        $this->resetForm();
        $this->sortOrder = (int) HeroCard::max('sort_order') + 1;
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $card = HeroCard::findOrFail($id);

        $this->resetForm();
        $this->editingId = $card->id;
        $this->title = $card->title;
        $this->subtitle = (string) $card->subtitle;
        $this->linkUrl = (string) $card->link_url;
        $this->learnUrl = (string) $card->learn_url;
        $this->buyUrl = (string) $card->buy_url;
        $this->focalX = $card->focal_x;
        $this->image = $card->image_path;
        $this->isEnabled = $card->is_enabled;
        $this->sortOrder = $card->sort_order;
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->validate();

        $card = $this->editingId
            ? HeroCard::findOrFail($this->editingId)
            : new HeroCard;

        // The image fills the lower half of the tile, so a card without one
        // would render broken on the storefront.
        if (! $this->imageUpload && $this->image === '') {
            $this->addError('imageUpload', 'Upload an image for the card.');

            return;
        }

        $imagePath = $this->image;

        if ($this->imageUpload) {
            $extension = strtolower($this->imageUpload->getClientOriginalExtension() ?: 'png');
            $filename = Str::slug($this->title ?: 'hero-card').'-'.Str::random(8).'.'.$extension;
            $destination = public_path('assets/hero-cards');
            if (! File::isDirectory($destination)) {
                File::makeDirectory($destination, 0755, true);
            }
            File::copy($this->imageUpload->getRealPath(), $destination.DIRECTORY_SEPARATOR.$filename);

            $imagePath = 'hero-cards/'.$filename;
        }

        // Clean up a replaced upload only once the change is committed.
        if ($card->exists && $card->image_path && $card->image_path !== $imagePath) {
            $this->deleteImageFile($card->image_path);
        }

        $card->fill([
            'title' => trim($this->title),
            'subtitle' => trim($this->subtitle) ?: null,
            'image_path' => $imagePath,
            'focal_x' => $this->focalX,
            'link_url' => trim($this->linkUrl) ?: null,
            'learn_url' => trim($this->learnUrl) ?: null,
            'buy_url' => trim($this->buyUrl) ?: null,
            'is_enabled' => $this->isEnabled,
            'sort_order' => $this->sortOrder,
        ])->save();

        $this->resetForm();
        $this->dispatch('toast', message: 'Hero card saved.');
    }

    public function toggle(int $id): void
    {
        $card = HeroCard::findOrFail($id);
        $card->is_enabled = ! $card->is_enabled;
        $card->save();
    }

    public function delete(int $id): void
    {
        $card = HeroCard::findOrFail($id);

        $this->deleteImageFile($card->image_path);
        $card->delete();

        if ($this->editingId === $id) {
            $this->resetForm();
        }

        $this->dispatch('toast', message: 'Hero card deleted.');
    }

    /** Remove an uploaded card image from disk. Only paths inside hero-cards/
     *  are touched, so seeded root assets and other files are never deleted. */
    private function deleteImageFile(?string $path): void
    {
        if ($path && str_starts_with($path, 'hero-cards/') && ! str_contains($path, '..')) {
            File::delete(public_path('assets/'.$path));
        }
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'showForm', 'title', 'subtitle', 'linkUrl', 'buyUrl', 'image', 'imageUpload', 'isEnabled']);
        $this->resetErrorBag();
        $this->learnUrl = '/help';
        $this->focalX = 50;
        $this->sortOrder = 1;
    }
} ?>

<div class="mx-auto max-w-5xl">
    <header class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Hero Cards</h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">The highlight tiles right under the <a href="/" target="_blank" class="text-blue-600 hover:underline dark:text-blue-300">homepage</a> hero. Cards missing their image file are hidden automatically.</p>
        </div>
        <div class="flex items-center gap-2">
            <span class="rounded-[12px] bg-zinc-100 px-3 py-1 text-xs font-semibold text-zinc-600 dark:bg-zinc-700/50 dark:text-zinc-300">{{ $this->cards->count() }} {{ \Illuminate\Support\Str::plural('card', $this->cards->count()) }}</span>
            <button wire:click="newCard" type="button" class="inline-flex items-center gap-1.5 rounded-[12px] bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-blue-700">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                New card
            </button>
        </div>
    </header>

    <div class="overflow-hidden rounded-[12px] bg-white shadow-sm shadow-zinc-900/[0.04] dark:bg-[#1d3252]">
        <div class="overflow-x-auto p-3">
            <table class="admin-table w-full text-left text-sm">
                <thead>
                    <tr>
                        <th>Preview</th>
                        <th>Card</th>
                        <th>Buttons</th>
                        <th>Order</th>
                        <th>Status</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->cards as $card)
                        <tr wire:key="hero-card-row-{{ $card->id }}">
                            <td>
                                <div class="flex h-12 w-20 items-center justify-center overflow-hidden rounded-[12px] bg-zinc-100 ring-1 ring-zinc-200 dark:bg-[#0c1a36] dark:ring-zinc-700/60">
                                    @if ($card->imageExists())
                                        <img src="{{ $card->imageUrl() }}" alt="" class="h-full w-full object-cover" style="object-position: {{ $card->focal_x }}% 50%;">
                                    @else
                                        <span class="text-[10px] font-semibold text-red-600 dark:text-red-400">Missing file</span>
                                    @endif
                                </div>
                            </td>
                            <td>
                                <p class="font-semibold text-zinc-900 dark:text-white">{{ $card->title }}</p>
                                @if ($card->subtitle)
                                    <p class="mt-0.5 max-w-[280px] truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $card->subtitle }}</p>
                                @endif
                            </td>
                            <td>
                                <div class="flex flex-wrap gap-1">
                                    @if ($card->learn_url)
                                        <x-admin.badge tone="blue">Learn more</x-admin.badge>
                                    @endif
                                    @if ($card->buy_url)
                                        <x-admin.badge tone="purple">Buy now</x-admin.badge>
                                    @endif
                                </div>
                            </td>
                            <td class="tabular-nums">{{ $card->sort_order }}</td>
                            <td>
                                <x-admin.badge :tone="$card->is_enabled ? 'emerald' : 'zinc'">
                                    {{ $card->is_enabled ? 'Live' : 'Off' }}
                                </x-admin.badge>
                            </td>
                            <td class="whitespace-nowrap text-right">
                                <div class="inline-flex items-center gap-1.5">
                                    <button wire:click="toggle({{ $card->id }})" type="button" class="rounded-[5px] bg-zinc-100 px-2.5 py-1 text-[11px] font-semibold text-zinc-700 transition-colors hover:bg-zinc-200 dark:bg-zinc-700/50 dark:text-zinc-300 dark:hover:bg-zinc-700">{{ $card->is_enabled ? 'Turn off' : 'Turn on' }}</button>
                                    <button wire:click="edit({{ $card->id }})" type="button" class="rounded-[5px] bg-blue-50 px-2.5 py-1 text-[11px] font-semibold text-blue-700 transition-colors hover:bg-blue-100 dark:bg-blue-500/15 dark:text-blue-300 dark:hover:bg-blue-500/25">Edit</button>
                                    <button wire:click="delete({{ $card->id }})" data-confirm="Delete this hero card permanently?" data-confirm-tone="danger" data-confirm-text="Delete" type="button" class="rounded-[5px] bg-red-50 px-2.5 py-1 text-[11px] font-semibold text-red-700 transition-colors hover:bg-red-100 dark:bg-red-500/15 dark:text-red-300 dark:hover:bg-red-500/25">Delete</button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- Create/edit modal --}}
    @if ($showForm)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true">
            <div wire:click="$set('showForm', false)" class="absolute inset-0 bg-zinc-900/40"></div>
            <form wire:submit="save" class="relative flex max-h-[90vh] w-full max-w-2xl flex-col overflow-hidden rounded-[12px] bg-white shadow-2xl dark:bg-[#1d3252]">
                <div class="flex shrink-0 items-start justify-between gap-4 border-b border-zinc-100 px-5 py-4 dark:border-zinc-700/60">
                    <h3 class="text-sm font-bold text-zinc-900 dark:text-white">{{ $editingId ? 'Edit card' : 'New card' }}</h3>
                    <button type="button" wire:click="$set('showForm', false)" aria-label="Close" class="flex h-8 w-8 items-center justify-center rounded-[12px] bg-zinc-100 text-zinc-600 transition-colors hover:bg-zinc-200 dark:bg-[#26416b] dark:text-zinc-300 dark:hover:bg-[#34507a]">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <div class="space-y-4 overflow-y-auto px-5 py-4">
                    <div>
                        <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Headline</label>
                        <input wire:model="title" type="text" placeholder="e.g. Buy Apple Gift Cards" class="mt-1.5 w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#0c1a36] dark:text-white">
                        @error('title') <p class="mt-1 text-[11px] font-medium text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Subtitle <span class="text-zinc-500 dark:text-zinc-400">(optional)</span></label>
                        <input wire:model="subtitle" type="text" placeholder="One supporting line under the headline." class="mt-1.5 w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#0c1a36] dark:text-white">
                        @error('subtitle') <p class="mt-1 text-[11px] font-medium text-red-600">{{ $message }}</p> @enderror
                    </div>

                    {{-- Image upload + focal point --}}
                    <div>
                        <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Card image</label>
                        <div class="mt-1.5 flex flex-col gap-3 sm:flex-row sm:items-center">
                            <div class="flex h-20 w-32 shrink-0 items-center justify-center overflow-hidden rounded-[12px] bg-zinc-100 ring-1 ring-zinc-200 dark:bg-[#0c1a36] dark:ring-zinc-700/60">
                                @if ($imageUpload)
                                    <img src="{{ $imageUpload->temporaryUrl() }}" alt="" class="h-full w-full object-cover" style="object-position: {{ $focalX }}% 50%;">
                                @elseif ($image)
                                    <img src="{{ asset('assets/'.$image) }}" alt="" class="h-full w-full object-cover" style="object-position: {{ $focalX }}% 50%;" onerror="this.style.display='none'">
                                @else
                                    <span class="text-[10px] text-zinc-500 dark:text-zinc-400">No image</span>
                                @endif
                            </div>
                            <div class="min-w-0 flex-1">
                                <input wire:model="imageUpload" type="file" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml" class="block w-full text-xs text-zinc-700 file:mr-3 file:rounded-[12px] file:border-0 file:bg-blue-600 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-white hover:file:bg-blue-700 dark:text-zinc-300">
                                <p class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400">PNG / JPG / WebP / GIF / SVG, up to 5MB. Stored at <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-700/50">public/assets/hero-cards/</code>.</p>
                                <div wire:loading wire:target="imageUpload" class="mt-1 text-[11px] font-medium text-blue-600">Uploading...</div>
                            </div>
                        </div>
                        @error('imageUpload') <p class="mt-1 text-[11px] font-medium text-red-600">{{ $message }}</p> @enderror

                        <div class="mt-3">
                            <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Focal point</label>
                            <p class="text-[11px] text-zinc-500 dark:text-zinc-400">Which part of the image stays in frame in the square crop: 0 = left edge, 50 = center, 100 = right edge. The preview above follows it.</p>
                            <input wire:model.live="focalX" type="range" min="0" max="100" step="1" class="mt-1.5 w-full accent-blue-600">
                            @error('focalX') <p class="mt-1 text-[11px] font-medium text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div>
                            <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Card link</label>
                            <input wire:model="linkUrl" type="text" placeholder="/gift-cards/apple" class="mt-1.5 w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#0c1a36] dark:text-white">
                            <p class="mt-1 text-[10px] text-zinc-500 dark:text-zinc-400">Clicking the card body goes here.</p>
                            @error('linkUrl') <p class="mt-1 text-[11px] font-medium text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Learn more link</label>
                            <input wire:model="learnUrl" type="text" placeholder="/help" class="mt-1.5 w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#0c1a36] dark:text-white">
                            <p class="mt-1 text-[10px] text-zinc-500 dark:text-zinc-400">Empty hides the button.</p>
                            @error('learnUrl') <p class="mt-1 text-[11px] font-medium text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Buy now link</label>
                            <input wire:model="buyUrl" type="text" placeholder="/gift-cards/apple" class="mt-1.5 w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#0c1a36] dark:text-white">
                            <p class="mt-1 text-[10px] text-zinc-500 dark:text-zinc-400">Empty hides the button.</p>
                            @error('buyUrl') <p class="mt-1 text-[11px] font-medium text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Sort order</label>
                            <input wire:model="sortOrder" type="number" min="0" max="10000" class="mt-1.5 w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-sm tabular-nums text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#0c1a36] dark:text-white">
                            @error('sortOrder') <p class="mt-1 text-[11px] font-medium text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <label class="flex items-center gap-2 self-end pb-2">
                            <input wire:model="isEnabled" type="checkbox" class="h-4 w-4 cursor-pointer accent-blue-600">
                            <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">Enabled</span>
                        </label>
                    </div>
                </div>
                <div class="flex shrink-0 items-center justify-end gap-2 border-t border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-700/60 dark:bg-[#0c1a36]/50">
                    <button type="button" wire:click="$set('showForm', false)" class="inline-flex items-center rounded-[12px] px-3.5 py-2 text-xs font-semibold text-zinc-700 transition-colors hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-[#26416b]">Cancel</button>
                    <button type="submit" class="inline-flex items-center rounded-[12px] bg-blue-600 px-4 py-2 text-xs font-semibold text-white transition-colors hover:bg-blue-700">{{ $editingId ? 'Save changes' : 'Create card' }}</button>
                </div>
            </form>
        </div>
    @endif
</div>
