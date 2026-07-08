<?php

use App\Models\HeroSection;
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
#[Title('Hero Sections')]
class extends Component {
    use WithFileUploads;

    public ?int $editingId = null;

    public bool $showForm = false;

    /** Whether the section being edited is the built-in designed hero. Its
     *  headline and CTAs live in the Blade component, so only media, enabled
     *  state, and sort order are editable. */
    public bool $editingBuiltin = false;

    #[Validate('nullable|string|max:120')]
    public string $title = '';

    #[Validate('nullable|string|max:300')]
    public string $subtitle = '';

    #[Validate('nullable|string|max:60')]
    public string $ctaLabel = '';

    #[Validate('nullable|string|max:500')]
    public string $ctaUrl = '';

    /** Existing media path (relative to public/assets/), kept when editing so
     *  the admin can keep or replace it. */
    #[Validate('nullable|string|max:500')]
    public string $media = '';

    public string $mediaType = '';

    /** Newly uploaded image or video. On save the file moves into
     *  public/assets/hero/ and the relative path is stored in the DB. */
    #[Validate('nullable|file|mimes:jpg,jpeg,png,webp,gif,mp4,webm|max:40960')] // 40MB cap (videos)
    public $mediaUpload = null;

    #[Validate('boolean')]
    public bool $isEnabled = true;

    #[Validate('integer|min:0|max:10000')]
    public int $sortOrder = 1;

    #[Computed]
    public function sections()
    {
        return HeroSection::orderBy('sort_order')->orderBy('id')->get();
    }

    public function newSection(): void
    {
        $this->resetForm();
        $this->sortOrder = (int) HeroSection::max('sort_order') + 1;
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $section = HeroSection::findOrFail($id);

        $this->resetForm();
        $this->editingId = $section->id;
        $this->editingBuiltin = $section->is_builtin;
        $this->title = (string) $section->title;
        $this->subtitle = (string) $section->subtitle;
        $this->ctaLabel = (string) $section->cta_label;
        $this->ctaUrl = (string) $section->cta_url;
        $this->media = (string) $section->media_path;
        $this->mediaType = (string) $section->media_type;
        $this->isEnabled = $section->is_enabled;
        $this->sortOrder = $section->sort_order;
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->validate();

        $section = $this->editingId
            ? HeroSection::findOrFail($this->editingId)
            : new HeroSection;

        // A custom slide with no headline and no media would render empty.
        if (! $section->is_builtin && ! $this->mediaUpload && $this->media === '' && trim($this->title) === '') {
            $this->addError('title', 'Give the section a headline or upload an image/video.');

            return;
        }

        $mediaPath = $this->media ?: null;
        $mediaType = $mediaPath ? ($this->mediaType ?: null) : null;

        // Move the upload to public/assets/hero/<filename>, replacing (and
        // cleaning up) any previous file for this section.
        if ($this->mediaUpload) {
            $extension = strtolower($this->mediaUpload->getClientOriginalExtension() ?: 'jpg');
            $filename = Str::slug($this->title ?: 'hero').'-'.Str::random(8).'.'.$extension;
            $destination = public_path('assets/hero');
            if (! File::isDirectory($destination)) {
                File::makeDirectory($destination, 0755, true);
            }
            File::copy($this->mediaUpload->getRealPath(), $destination.DIRECTORY_SEPARATOR.$filename);

            $mediaPath = 'hero/'.$filename;
            $mediaType = in_array($extension, ['mp4', 'webm'], true) ? 'video' : 'image';
        }

        // Clean up the previous file only once the change is committed, so a
        // cancelled edit never orphans the media a live section points at.
        if ($section->exists && $section->media_path && $section->media_path !== $mediaPath) {
            $this->deleteMediaFile($section->media_path);
        }

        $payload = [
            'media_path' => $mediaPath,
            'media_type' => $mediaType,
            'is_enabled' => $this->isEnabled,
            'sort_order' => $this->sortOrder,
        ];

        // The built-in hero keeps its designed headline and CTAs.
        if (! $section->is_builtin) {
            $payload += [
                'title' => trim($this->title) ?: null,
                'subtitle' => trim($this->subtitle) ?: null,
                'cta_label' => trim($this->ctaLabel) ?: null,
                'cta_url' => trim($this->ctaUrl) ?: null,
            ];
        }

        $section->fill($payload)->save();

        $this->resetForm();
        session()->flash('status', 'Hero section saved.');
    }

    public function toggle(int $id): void
    {
        $section = HeroSection::findOrFail($id);
        $section->is_enabled = ! $section->is_enabled;
        $section->save();
    }

    /** Clear the form's media so the built-in hero falls back to its dotted
     *  backdrop and custom sections become text-only. The file itself is only
     *  removed from disk on save, so cancelling keeps the live section intact. */
    public function removeMedia(): void
    {
        $this->media = '';
        $this->mediaType = '';
        $this->mediaUpload = null;
    }

    public function delete(int $id): void
    {
        $section = HeroSection::findOrFail($id);

        // The built-in designed hero can be disabled but never deleted.
        if ($section->is_builtin) {
            return;
        }

        $this->deleteMediaFile($section->media_path);
        $section->delete();

        if ($this->editingId === $id) {
            $this->resetForm();
        }

        session()->flash('status', 'Hero section deleted.');
    }

    /** Remove an uploaded hero file from disk. Only paths inside hero/ are
     *  touched so a bad DB value can never delete other public assets. */
    private function deleteMediaFile(?string $path): void
    {
        if ($path && str_starts_with($path, 'hero/') && ! str_contains($path, '..')) {
            File::delete(public_path('assets/'.$path));
        }
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'editingBuiltin', 'showForm', 'title', 'subtitle', 'ctaLabel', 'ctaUrl', 'media', 'mediaType', 'mediaUpload', 'isEnabled']);
        $this->resetErrorBag();
        $this->sortOrder = 1;
    }
} ?>

<div class="mx-auto max-w-5xl">
    <header class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Hero Sections</h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">Slides shown at the top of the <a href="/" target="_blank" class="text-blue-600 hover:underline dark:text-blue-300">homepage</a>. One enabled section shows on its own; two or more rotate as a slider.</p>
        </div>
        <div class="flex items-center gap-2">
            <span class="rounded-[12px] bg-zinc-100 px-3 py-1 text-xs font-semibold text-zinc-600 dark:bg-zinc-700/50 dark:text-zinc-300">{{ $this->sections->count() }} {{ \Illuminate\Support\Str::plural('section', $this->sections->count()) }}</span>
            <button wire:click="newSection" type="button" class="inline-flex items-center gap-1.5 rounded-[12px] bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-blue-700">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                New section
            </button>
        </div>
    </header>

    @if (session('status'))
        <div class="mb-4 rounded-[12px] bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-500/15 dark:text-emerald-300 dark:ring-emerald-500/30">{{ session('status') }}</div>
    @endif

    <div class="overflow-hidden rounded-[12px] bg-white shadow-sm shadow-zinc-900/[0.04] ring-1 ring-zinc-200 dark:bg-[#1d3252] dark:ring-white/10">
        <div class="overflow-x-auto p-3">
            <table class="admin-table w-full text-left text-sm">
                <thead>
                    <tr>
                        <th>Preview</th>
                        <th>Section</th>
                        <th>Media</th>
                        <th>Order</th>
                        <th>Status</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->sections as $section)
                        <tr wire:key="hero-section-{{ $section->id }}">
                            <td>
                                <div class="flex h-12 w-20 items-center justify-center overflow-hidden rounded-[12px] bg-zinc-100 ring-1 ring-zinc-200 dark:bg-[#0c1a36] dark:ring-zinc-700/60">
                                    @if ($section->media_type === 'video')
                                        <video src="{{ $section->mediaUrl() }}" class="h-full w-full object-cover" muted playsinline preload="metadata"></video>
                                    @elseif ($section->media_type === 'image')
                                        <img src="{{ $section->mediaUrl() }}" alt="" class="h-full w-full object-cover" onerror="this.style.display='none'">
                                    @elseif ($section->is_builtin)
                                        {{-- Miniature of the designed dotted backdrop --}}
                                        <div class="h-full w-full" style="background-image: radial-gradient(circle, rgb(191 219 254) 2px, transparent 2.5px); background-size: 10px 10px;" aria-hidden="true"></div>
                                    @else
                                        <span class="text-[10px] text-zinc-500 dark:text-zinc-400">Text only</span>
                                    @endif
                                </div>
                            </td>
                            <td>
                                <p class="font-semibold text-zinc-900 dark:text-white">{{ $section->is_builtin ? 'Default hero' : ($section->title ?: 'Untitled') }}</p>
                                @if ($section->is_builtin)
                                    <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">The designed storefront hero. Headline and buttons are fixed.</p>
                                @elseif ($section->subtitle)
                                    <p class="mt-0.5 max-w-[280px] truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $section->subtitle }}</p>
                                @endif
                            </td>
                            <td>
                                @if ($section->media_type)
                                    <x-admin.badge tone="blue">{{ ucfirst($section->media_type) }}</x-admin.badge>
                                @else
                                    <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $section->is_builtin ? 'Dotted backdrop' : 'None' }}</span>
                                @endif
                            </td>
                            <td class="tabular-nums">{{ $section->sort_order }}</td>
                            <td>
                                <x-admin.badge :tone="$section->is_enabled ? 'emerald' : 'zinc'">
                                    {{ $section->is_enabled ? 'Live' : 'Off' }}
                                </x-admin.badge>
                            </td>
                            <td class="whitespace-nowrap text-right">
                                <div class="inline-flex items-center gap-1.5">
                                    <button wire:click="toggle({{ $section->id }})" type="button" class="rounded-[5px] bg-zinc-100 px-2.5 py-1 text-[11px] font-semibold text-zinc-700 transition-colors hover:bg-zinc-200 dark:bg-zinc-700/50 dark:text-zinc-300 dark:hover:bg-zinc-700">{{ $section->is_enabled ? 'Turn off' : 'Turn on' }}</button>
                                    <button wire:click="edit({{ $section->id }})" type="button" class="rounded-[5px] bg-blue-50 px-2.5 py-1 text-[11px] font-semibold text-blue-700 transition-colors hover:bg-blue-100 dark:bg-blue-500/15 dark:text-blue-300 dark:hover:bg-blue-500/25">Edit</button>
                                    @unless ($section->is_builtin)
                                        <button wire:click="delete({{ $section->id }})" wire:confirm="Delete this hero section permanently?" type="button" class="rounded-[5px] bg-red-50 px-2.5 py-1 text-[11px] font-semibold text-red-700 transition-colors hover:bg-red-100 dark:bg-red-500/15 dark:text-red-300 dark:hover:bg-red-500/25">Delete</button>
                                    @endunless
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
                    <h3 class="text-sm font-bold text-zinc-900 dark:text-white">{{ $editingBuiltin ? 'Edit default hero' : ($editingId ? 'Edit section' : 'New section') }}</h3>
                    <button type="button" wire:click="$set('showForm', false)" aria-label="Close" class="flex h-8 w-8 items-center justify-center rounded-[12px] bg-zinc-100 text-zinc-600 transition-colors hover:bg-zinc-200 dark:bg-[#26416b] dark:text-zinc-300 dark:hover:bg-[#34507a]">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <div class="space-y-4 overflow-y-auto px-5 py-4">

                    @if ($editingBuiltin)
                        <div class="rounded-[12px] bg-blue-50 px-4 py-3 text-xs font-medium text-blue-700 ring-1 ring-blue-200 dark:bg-blue-500/15 dark:text-blue-300 dark:ring-blue-500/30">
                            This is the designed storefront hero. Its headline and buttons are fixed; you can replace the dotted backdrop with an image or video, change its order, or turn it off.
                        </div>
                    @else
                        <div>
                            <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Headline</label>
                            <input wire:model="title" type="text" placeholder="e.g. Black Friday gift card deals" class="mt-1.5 w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#0c1a36] dark:text-white">
                            @error('title') <p class="mt-1 text-[11px] font-medium text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Subtitle <span class="text-zinc-500 dark:text-zinc-400">(optional)</span></label>
                            <textarea wire:model="subtitle" rows="2" placeholder="One or two supporting lines under the headline." class="mt-1.5 w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#0c1a36] dark:text-white"></textarea>
                            @error('subtitle') <p class="mt-1 text-[11px] font-medium text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Button label <span class="text-zinc-500 dark:text-zinc-400">(optional)</span></label>
                                <input wire:model="ctaLabel" type="text" placeholder="e.g. Shop deals" class="mt-1.5 w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#0c1a36] dark:text-white">
                                @error('ctaLabel') <p class="mt-1 text-[11px] font-medium text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Button link</label>
                                <input wire:model="ctaUrl" type="text" placeholder="/gift-cards or https://..." class="mt-1.5 w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#0c1a36] dark:text-white">
                                @error('ctaUrl') <p class="mt-1 text-[11px] font-medium text-red-600">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    @endif

                    {{-- Image / video upload --}}
                    <div>
                        <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">{{ $editingBuiltin ? 'Backdrop image or video' : 'Image or video' }}</label>
                        <div class="mt-1.5 flex flex-col gap-3 sm:flex-row sm:items-center">
                            <div class="flex h-20 w-32 shrink-0 items-center justify-center overflow-hidden rounded-[12px] bg-zinc-100 ring-1 ring-zinc-200 dark:bg-[#0c1a36] dark:ring-zinc-700/60">
                                @if ($mediaUpload && str_starts_with((string) $mediaUpload->getMimeType(), 'video'))
                                    <video src="{{ $mediaUpload->temporaryUrl() }}" class="h-full w-full object-cover" muted playsinline></video>
                                @elseif ($mediaUpload)
                                    <img src="{{ $mediaUpload->temporaryUrl() }}" alt="" class="h-full w-full object-cover">
                                @elseif ($media && $mediaType === 'video')
                                    <video src="{{ asset('assets/'.$media) }}" class="h-full w-full object-cover" muted playsinline preload="metadata"></video>
                                @elseif ($media)
                                    <img src="{{ asset('assets/'.$media) }}" alt="" class="h-full w-full object-cover" onerror="this.style.display='none'">
                                @else
                                    <span class="text-[10px] text-zinc-500 dark:text-zinc-400">{{ $editingBuiltin ? 'Dotted backdrop' : 'No media' }}</span>
                                @endif
                            </div>
                            <div class="min-w-0 flex-1">
                                <input wire:model="mediaUpload" type="file" accept="image/png,image/jpeg,image/webp,image/gif,video/mp4,video/webm" class="block w-full text-xs text-zinc-700 file:mr-3 file:rounded-[12px] file:border-0 file:bg-blue-600 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-white hover:file:bg-blue-700 dark:text-zinc-300">
                                <p class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400">PNG / JPG / WebP / GIF or MP4 / WebM, up to 40MB. Stored at <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-700/50">public/assets/hero/</code>.</p>
                                <div wire:loading wire:target="mediaUpload" class="mt-1 text-[11px] font-medium text-blue-600">Uploading...</div>
                                @if ($media || $mediaUpload)
                                    <button type="button" wire:click="removeMedia" class="mt-1.5 rounded-[5px] bg-red-50 px-2.5 py-1 text-[11px] font-semibold text-red-700 transition-colors hover:bg-red-100 dark:bg-red-500/15 dark:text-red-300 dark:hover:bg-red-500/25">Remove media</button>
                                @endif
                            </div>
                        </div>
                        @error('mediaUpload') <p class="mt-1 text-[11px] font-medium text-red-600">{{ $message }}</p> @enderror
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
                    <button type="submit" class="inline-flex items-center rounded-[12px] bg-blue-600 px-4 py-2 text-xs font-semibold text-white transition-colors hover:bg-blue-700">{{ $editingId ? 'Save changes' : 'Create section' }}</button>
                </div>
            </form>
        </div>
    @endif
</div>
