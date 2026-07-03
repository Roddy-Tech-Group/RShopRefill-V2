<?php

use App\Models\BlogPost;
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
#[Title('Blog Posts')]
class extends Component {
    use WithFileUploads;

    public ?int $editingId = null;

    public bool $showForm = false;

    /** "existing" lets the editor pick a category that's already in use;
     *  "new" reveals a free-text input. Prevents accidental "Product" vs
     *  "product" forks when categories are typed manually. */
    public string $categoryMode = 'existing';

    #[Validate('required|string|max:200')]
    public string $title = '';

    #[Validate('required|string|max:200')]
    public string $slug = '';

    #[Validate('required|string|max:80')]
    public string $category = '';

    #[Validate('required|string|max:500')]
    public string $excerpt = '';

    /** Existing image filename (relative to public/assets/), shown as a
     *  preview when editing so the user can keep or replace it. */
    #[Validate('nullable|string|max:500')]
    public string $image = '';

    /** Newly uploaded hero image. When set on save, replaces $image — the
     *  file moves into public/assets/blog/ and the relative path is
     *  stored in the DB. */
    #[Validate('nullable|image|max:5120')] // 5MB cap
    public $imageUpload = null;

    /** Optional downloadable attachment — PDF, slide deck, sample code zip,
     *  etc. Stored at public/assets/blog/<filename>. The public blog post
     *  page renders a download button when this is set. */
    #[Validate('nullable|string|max:500')]
    public string $attachment = '';

    /** Newly uploaded attachment file. 20MB cap. */
    #[Validate('nullable|file|max:20480|mimes:pdf,zip,doc,docx,png,jpg,jpeg,mp4')]
    public $attachmentUpload = null;

    /** Display label for the download button. */
    #[Validate('nullable|string|max:80')]
    public string $attachmentLabel = '';

    #[Validate('required|string|max:80')]
    public string $author = '';

    #[Validate('nullable|string|max:40')]
    public string $readTime = '';

    #[Validate('required|date')]
    public string $publishedAt = '';

    #[Validate('required|string|max:50000')]
    public string $bodyText = '';

    #[Validate('boolean')]
    public bool $isPublished = true;

    #[Validate('integer|min:0|max:10000')]
    public int $sortOrder = 0;

    #[Computed]
    public function posts()
    {
        return BlogPost::orderByDesc('published_at')->get();
    }

    /** Distinct categories already in use, alphabetical. Powers the
     *  "Use existing" dropdown in the create/edit modal. */
    #[Computed]
    public function existingCategories(): array
    {
        return BlogPost::query()
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->all();
    }

    public function newPost(): void
    {
        $this->resetForm();
        $this->publishedAt = now()->toDateString();
        $this->categoryMode = ! empty($this->existingCategories) ? 'existing' : 'new';
        if ($this->categoryMode === 'existing') {
            $this->category = $this->existingCategories[0];
        }
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $post = BlogPost::findOrFail($id);
        $this->editingId = $post->id;
        $this->title = $post->title;
        $this->slug = $post->slug;
        $this->category = $post->category;
        $this->excerpt = $post->excerpt;
        $this->image = (string) $post->image;
        $this->attachment = (string) $post->attachment_path;
        $this->attachmentLabel = (string) $post->attachment_label;
        $this->author = $post->author;
        $this->readTime = (string) ($post->read_time ?? '');
        $this->publishedAt = $post->published_at->toDateString();
        $this->bodyText = is_array($post->body) ? implode("\n\n", $post->body) : (string) $post->body;
        $this->isPublished = $post->is_published;
        $this->sortOrder = $post->sort_order;
        $this->categoryMode = in_array($post->category, $this->existingCategories, true) ? 'existing' : 'new';
        $this->showForm = true;
    }

    /** Swap the category picker source between existing-dropdown and free text. */
    public function setCategoryMode(string $mode): void
    {
        $this->categoryMode = $mode === 'new' ? 'new' : 'existing';
        if ($this->categoryMode === 'existing') {
            $this->category = $this->existingCategories[0] ?? '';
        } else {
            $this->category = '';
        }
    }

    public function updatedTitle(string $value): void
    {
        if (! $this->editingId && $this->slug === '') {
            $this->slug = Str::slug($value);
        }
    }

    public function save(): void
    {
        $this->validate();

        // Move uploaded image to public/assets/blog/<filename>.
        $imagePath = $this->image ?: 'placeholder.png';
        if ($this->imageUpload) {
            $extension = $this->imageUpload->getClientOriginalExtension() ?: 'jpg';
            $filename = Str::slug($this->title ?: 'blog').'-'.Str::random(8).'.'.$extension;
            $destination = public_path('assets/blog');
            if (! File::isDirectory($destination)) {
                File::makeDirectory($destination, 0755, true);
            }
            File::copy($this->imageUpload->getRealPath(), $destination.DIRECTORY_SEPARATOR.$filename);
            $imagePath = 'blog/'.$filename;
        }

        // Same flow for the downloadable attachment.
        $attachmentPath = $this->attachment ?: null;
        if ($this->attachmentUpload) {
            $extension = $this->attachmentUpload->getClientOriginalExtension() ?: 'pdf';
            $filename = Str::slug($this->title ?: 'blog').'-attachment-'.Str::random(8).'.'.$extension;
            $destination = public_path('assets/blog');
            if (! File::isDirectory($destination)) {
                File::makeDirectory($destination, 0755, true);
            }
            File::copy($this->attachmentUpload->getRealPath(), $destination.DIRECTORY_SEPARATOR.$filename);
            $attachmentPath = 'blog/'.$filename;
        }

        // Split body on blank lines back into the array shape the model expects.
        $bodyParagraphs = collect(preg_split("/\n\s*\n/", trim($this->bodyText)))
            ->map(fn ($p) => trim($p))
            ->filter()
            ->values()
            ->all();

        $payload = [
            'title' => $this->title,
            'slug' => Str::slug($this->slug),
            'category' => $this->category,
            'excerpt' => $this->excerpt,
            'image' => $imagePath,
            'attachment_path' => $attachmentPath,
            'attachment_label' => $attachmentPath ? ($this->attachmentLabel ?: 'Download file') : null,
            'body' => $bodyParagraphs,
            'author' => $this->author,
            'read_time' => $this->readTime ?: null,
            'published_at' => $this->publishedAt,
            'is_published' => $this->isPublished,
            'sort_order' => $this->sortOrder,
        ];

        if ($this->editingId) {
            BlogPost::findOrFail($this->editingId)->update($payload);
            session()->flash('status', 'Post updated.');
        } else {
            BlogPost::create($payload);
            session()->flash('status', 'Post created.');
        }

        $this->resetForm();
        unset($this->posts);
    }

    public function delete(int $id): void
    {
        BlogPost::findOrFail($id)->delete();
        session()->flash('status', 'Post deleted.');
        unset($this->posts);
    }

    public function togglePublish(int $id): void
    {
        $post = BlogPost::findOrFail($id);
        $post->update(['is_published' => ! $post->is_published]);
        unset($this->posts);
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->title = '';
        $this->slug = '';
        $this->category = '';
        $this->categoryMode = 'existing';
        $this->excerpt = '';
        $this->image = '';
        $this->imageUpload = null;
        $this->attachment = '';
        $this->attachmentUpload = null;
        $this->attachmentLabel = '';
        $this->author = '';
        $this->readTime = '';
        $this->publishedAt = now()->toDateString();
        $this->bodyText = '';
        $this->isPublished = true;
        $this->sortOrder = 0;
        $this->showForm = false;
        $this->resetValidation();
    }
}; ?>

<div class="w-full px-4 py-8 sm:px-6 lg:px-8">

    <header class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Blog Posts</h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">CMS-managed articles shown at <a href="/blog" target="_blank" class="text-blue-600 hover:underline dark:text-blue-300">/blog</a>.</p>
        </div>
        <div class="flex items-center gap-2">
            <span class="rounded-[12px] bg-zinc-100 px-3 py-1 text-xs font-semibold text-zinc-600 dark:bg-zinc-700/50 dark:text-zinc-300">{{ $this->posts->count() }} {{ \Illuminate\Support\Str::plural('post', $this->posts->count()) }}</span>
            <button wire:click="newPost" type="button" class="inline-flex items-center gap-1.5 rounded-[12px] bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-blue-700">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                New post
            </button>
        </div>
    </header>

    @if (session('status'))
        <div class="mb-4 rounded-[12px] bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-500/15 dark:text-emerald-300 dark:ring-emerald-500/30">{{ session('status') }}</div>
    @endif

    <div class="overflow-hidden rounded-[12px] border-[1.5px] border-white bg-white shadow-sm shadow-zinc-900/[0.04] dark:border-white dark:bg-[#1d3252]">
        <div class="overflow-x-auto p-3">
            <table class="admin-table w-full text-left text-sm">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Category</th>
                        <th>Published</th>
                        <th>Status</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->posts as $post)
                        <tr>
                            <td>
                                <p class="font-semibold text-zinc-900 dark:text-white">{{ $post->title }}</p>
                                <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">/{{ $post->slug }}</p>
                            </td>
                            <td>{{ $post->category }}</td>
                            <td>{{ $post->published_at->format('M j, Y') }}</td>
                            <td>
                                <x-admin.badge :tone="$post->is_published ? 'emerald' : 'zinc'">
                                    {{ $post->is_published ? 'Published' : 'Draft' }}
                                </x-admin.badge>
                            </td>
                            <td class="whitespace-nowrap text-right">
                                <div class="inline-flex items-center gap-1.5">
                                    <button wire:click="togglePublish({{ $post->id }})" type="button" class="rounded-[5px] bg-zinc-100 px-2.5 py-1 text-[11px] font-semibold text-zinc-700 transition-colors hover:bg-zinc-200 dark:bg-zinc-700/50 dark:text-zinc-300 dark:hover:bg-zinc-700">{{ $post->is_published ? 'Unpublish' : 'Publish' }}</button>
                                    <button wire:click="edit({{ $post->id }})" type="button" class="rounded-[5px] bg-blue-50 px-2.5 py-1 text-[11px] font-semibold text-blue-700 transition-colors hover:bg-blue-100 dark:bg-blue-500/15 dark:text-blue-300 dark:hover:bg-blue-500/25">Edit</button>
                                    <button wire:click="delete({{ $post->id }})" wire:confirm="Delete this post permanently?" type="button" class="rounded-[5px] bg-red-50 px-2.5 py-1 text-[11px] font-semibold text-red-700 transition-colors hover:bg-red-100 dark:bg-red-500/15 dark:text-red-300 dark:hover:bg-red-500/25">Delete</button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-12 text-center text-sm text-zinc-600 dark:text-zinc-400">No posts yet. Click "New post" to create the first one.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Create/edit modal --}}
    @if ($showForm)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true">
            <div wire:click="$set('showForm', false)" class="absolute inset-0 bg-zinc-900/40"></div>
            <form wire:submit="save" class="relative max-h-[90vh] w-full max-w-2xl overflow-hidden rounded-[12px] bg-white shadow-2xl flex flex-col dark:bg-[#1d3252]">
                <div class="flex shrink-0 items-start justify-between gap-4 border-b border-zinc-100 px-5 py-4 dark:border-zinc-700/60">
                    <h3 class="text-sm font-bold text-zinc-900 dark:text-white">{{ $editingId ? 'Edit post' : 'New post' }}</h3>
                    <button type="button" wire:click="$set('showForm', false)" aria-label="Close" class="flex h-8 w-8 items-center justify-center rounded-[12px] bg-zinc-100 text-zinc-600 transition-colors hover:bg-zinc-200 dark:bg-[#26416b] dark:text-zinc-300 dark:hover:bg-[#34507a]">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <div class="space-y-4 overflow-y-auto px-5 py-4">
                    <div>
                        <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Title</label>
                        <input wire:model.live.debounce.300ms="title" type="text" class="mt-1.5 w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#0c1a36] dark:text-white">
                        @error('title') <p class="mt-1 text-[11px] font-medium text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Slug</label>
                            <input wire:model="slug" type="text" placeholder="auto-generated from title" class="mt-1.5 w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#0c1a36] dark:text-white">
                            @error('slug') <p class="mt-1 text-[11px] font-medium text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Category</label>

                            {{-- Mode toggle: pick from existing categories or create new --}}
                            @if (! empty($this->existingCategories))
                                <div class="mt-1.5 inline-flex items-center rounded-[12px] bg-zinc-100 p-1 dark:bg-[#26416b]" role="tablist" aria-label="Category source">
                                    <button
                                        type="button"
                                        wire:click="setCategoryMode('existing')"
                                        role="tab"
                                        aria-selected="{{ $categoryMode === 'existing' ? 'true' : 'false' }}"
                                        @class([
                                            'rounded-[12px] px-3 py-1.5 text-xs font-semibold transition-colors',
                                            'bg-white text-zinc-900 shadow-sm ring-1 ring-zinc-200 dark:bg-[#1d3252] dark:text-white dark:ring-zinc-700/60' => $categoryMode === 'existing',
                                            'text-zinc-600 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white' => $categoryMode !== 'existing',
                                        ])
                                    >Use existing</button>
                                    <button
                                        type="button"
                                        wire:click="setCategoryMode('new')"
                                        role="tab"
                                        aria-selected="{{ $categoryMode === 'new' ? 'true' : 'false' }}"
                                        @class([
                                            'rounded-[12px] px-3 py-1.5 text-xs font-semibold transition-colors',
                                            'bg-white text-zinc-900 shadow-sm ring-1 ring-zinc-200 dark:bg-[#1d3252] dark:text-white dark:ring-zinc-700/60' => $categoryMode === 'new',
                                            'text-zinc-600 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white' => $categoryMode !== 'new',
                                        ])
                                    >Create new</button>
                                </div>
                            @endif

                            @if ($categoryMode === 'existing' && ! empty($this->existingCategories))
                                @php $categoryChoices = array_combine($this->existingCategories, $this->existingCategories); @endphp
                                <x-admin.select wire:model="category" :options="$categoryChoices" />
                            @else
                                <input wire:model="category" type="text" placeholder="e.g. Product" class="mt-1.5 w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#0c1a36] dark:text-white">
                            @endif
                            @error('category') <p class="mt-1 text-[11px] font-medium text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div>
                        <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Excerpt</label>
                        <textarea wire:model="excerpt" rows="2" placeholder="Short summary shown on the blog index." class="mt-1.5 w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#0c1a36] dark:text-white"></textarea>
                        @error('excerpt') <p class="mt-1 text-[11px] font-medium text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Body</label>
                        <p class="text-[11px] text-zinc-500 dark:text-zinc-400">Separate paragraphs with a blank line.</p>
                        <textarea wire:model="bodyText" rows="10" class="mt-1.5 w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#0c1a36] dark:text-white"></textarea>
                        @error('bodyText') <p class="mt-1 text-[11px] font-medium text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Author</label>
                            <input wire:model="author" type="text" class="mt-1.5 w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#0c1a36] dark:text-white">
                            @error('author') <p class="mt-1 text-[11px] font-medium text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Read time</label>
                            <input wire:model="readTime" type="text" placeholder="e.g. 4 min read" class="mt-1.5 w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#0c1a36] dark:text-white">
                        </div>
                    </div>

                    {{-- Hero image upload --}}
                    <div>
                        <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Hero image</label>
                        <div class="mt-1.5 flex flex-col gap-3 sm:flex-row sm:items-center">
                            <div class="flex h-20 w-32 shrink-0 items-center justify-center overflow-hidden rounded-[12px] bg-zinc-100 ring-1 ring-zinc-200 dark:bg-[#0c1a36] dark:ring-zinc-700/60">
                                @if ($imageUpload)
                                    <img src="{{ $imageUpload->temporaryUrl() }}" alt="" class="h-full w-full object-cover">
                                @elseif ($image)
                                    <img src="{{ asset('assets/' . $image) }}" alt="" class="h-full w-full object-cover" onerror="this.style.display='none'">
                                @else
                                    <span class="text-[10px] text-zinc-500 dark:text-zinc-400">No image</span>
                                @endif
                            </div>
                            <div class="min-w-0 flex-1">
                                <input wire:model="imageUpload" type="file" accept="image/png,image/jpeg,image/webp,image/gif" class="block w-full text-xs text-zinc-700 file:mr-3 file:rounded-[12px] file:border-0 file:bg-blue-600 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-white hover:file:bg-blue-700 dark:text-zinc-300">
                                <p class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400">PNG / JPG / WebP / GIF, up to 5MB. Stored at <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-700/50">public/assets/blog/</code>.</p>
                                <div wire:loading wire:target="imageUpload" class="mt-1 text-[11px] font-medium text-blue-600">Uploading…</div>
                            </div>
                        </div>
                        @error('imageUpload') <p class="mt-1 text-[11px] font-medium text-red-600">{{ $message }}</p> @enderror
                    </div>

                    {{-- Optional download attachment --}}
                    <div class="rounded-[12px] border border-dashed border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700/60 dark:bg-[#0c1a36]/50">
                        <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Downloadable attachment <span class="text-zinc-500 dark:text-zinc-400">(optional)</span></label>
                        <p class="mt-0.5 text-[11px] text-zinc-500 dark:text-zinc-400">Adds a "Download" button to the public blog post. PDF, ZIP, DOC, PNG, JPG, MP4 — up to 20MB.</p>

                        <div class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-center">
                            <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-[12px] bg-white ring-1 ring-zinc-200 dark:bg-[#0c1a36] dark:ring-zinc-700/60">
                                @if ($attachmentUpload || $attachment)
                                    <svg class="h-5 w-5 text-blue-600 dark:text-blue-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9z"/>
                                    </svg>
                                @else
                                    <svg class="h-5 w-5 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75z"/>
                                    </svg>
                                @endif
                            </div>
                            <div class="min-w-0 flex-1">
                                @if ($attachment && ! $attachmentUpload)
                                    <p class="truncate text-xs font-mono text-zinc-700 dark:text-zinc-300">{{ $attachment }}</p>
                                @endif
                                <input wire:model="attachmentUpload" type="file" accept=".pdf,.zip,.doc,.docx,.png,.jpg,.jpeg,.mp4" class="mt-1 block w-full text-xs text-zinc-700 file:mr-3 file:rounded-[12px] file:border-0 file:bg-blue-600 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-white hover:file:bg-blue-700 dark:text-zinc-300">
                                <div wire:loading wire:target="attachmentUpload" class="mt-1 text-[11px] font-medium text-blue-600">Uploading…</div>
                            </div>
                        </div>
                        @error('attachmentUpload') <p class="mt-1 text-[11px] font-medium text-red-600">{{ $message }}</p> @enderror

                        @if ($attachmentUpload || $attachment)
                            <div class="mt-3">
                                <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Button label</label>
                                <input wire:model="attachmentLabel" type="text" placeholder="e.g. Download whitepaper" class="mt-1.5 w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#0c1a36] dark:text-white">
                                <p class="mt-1 text-[10px] text-zinc-500 dark:text-zinc-400">Defaults to "Download file" when left blank.</p>
                            </div>
                        @endif
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Published at</label>
                            <input wire:model="publishedAt" type="date" class="mt-1.5 w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#0c1a36] dark:text-white">
                            @error('publishedAt') <p class="mt-1 text-[11px] font-medium text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">Sort order</label>
                            <input wire:model="sortOrder" type="number" min="0" max="10000" class="mt-1.5 w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-2 text-sm tabular-nums text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#0c1a36] dark:text-white">
                        </div>
                    </div>
                    <label class="flex items-center gap-2">
                        <input wire:model="isPublished" type="checkbox" class="h-4 w-4 cursor-pointer accent-blue-600">
                        <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">Published</span>
                    </label>
                </div>
                <div class="flex shrink-0 items-center justify-end gap-2 border-t border-zinc-100 bg-zinc-50 px-5 py-3 dark:border-zinc-700/60 dark:bg-[#0c1a36]/50">
                    <button type="button" wire:click="$set('showForm', false)" class="inline-flex items-center rounded-[12px] px-3.5 py-2 text-xs font-semibold text-zinc-700 transition-colors hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-[#26416b]">Cancel</button>
                    <button type="submit" class="inline-flex items-center rounded-[12px] bg-blue-600 px-4 py-2 text-xs font-semibold text-white transition-colors hover:bg-blue-700">{{ $editingId ? 'Save changes' : 'Create post' }}</button>
                </div>
            </form>
        </div>
    @endif
</div>
