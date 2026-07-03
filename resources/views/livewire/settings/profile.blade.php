<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.dashboard')] class extends Component {
    use WithFileUploads;

    public string $name = '';
    public string $email = '';
    public ?string $phone = null;
    public ?string $country = null;
    public ?string $gender = null;
    public $avatar = null;

    // Notification preferences (NotificationPreference model). Saved instantly on toggle.
    public bool $notifyEmail = true;
    public bool $notifyPush = true;
    public bool $notifyMarketing = true;
    public bool $notifyOrders = true;
    public bool $notifyWallet = true;
    public bool $notifySecurity = true;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $user = Auth::user();
        $this->name    = $user->name;
        $this->email   = $user->email;
        $this->phone   = $user->phone;
        $this->country = $user->country;
        $this->gender  = $user->gender;

        $prefs = app(\App\Domain\Notification\Services\NotificationPreferenceService::class)->getPreferences($user);
        $this->notifyEmail     = (bool) $prefs->email_enabled;
        $this->notifyPush      = (bool) $prefs->push_enabled;
        $this->notifyMarketing = (bool) $prefs->marketing_enabled;
        $this->notifyOrders    = (bool) $prefs->order_notifications;
        $this->notifyWallet    = (bool) $prefs->wallet_notifications;
        $this->notifySecurity  = (bool) $prefs->security_notifications;
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],

            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($user->id)
            ],

            // Phone: free-form for now, allows + and digits and spaces. Backend can swap in a stricter rule
            // (libphonenumber, country-aware) once a phone library ships.
            'phone'  => ['nullable', 'string', 'max:32', 'regex:/^[\d\s\+\-\(\)]+$/'],

            // Account country - the user's declared country (profile attribute,
            // shown in their info and to admins). Constrained to the known
            // country list so junk can't be stored. NOT the shopping region.
            'country' => ['nullable', 'string', Rule::in(array_keys(config('countries.codes', [])))],

            // Gender enum stored as a short string. Add new values to the migration column and the union here.
            'gender' => ['nullable', 'in:male,female,other'],
        ]);

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        $this->dispatch('profile-updated', name: $user->name);
    }

    /**
     * Upload a new avatar. Validates the image, stores it on the public disk
     * under avatars/, and writes the public URL onto the user's avatar_url.
     */
    public function updateAvatar(): void
    {
        $this->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:2048'],
        ]);

        $user = Auth::user();

        // Clean up the previous uploaded avatar (skip external URLs like Google).
        if ($user->avatar_url && str_starts_with($user->avatar_url, '/storage/avatars/')) {
            $previous = str_replace('/storage/', '', $user->avatar_url);
            Storage::disk('public')->delete($previous);
        }

        $path = $this->avatar->store('avatars/'.$user->id, 'public');
        $user->update(['avatar_url' => Storage::url($path)]);

        $this->reset('avatar');
        $this->dispatch('avatar-updated');
    }

    /**
     * Remove the current avatar, falling back to the default icon.
     */
    public function removeAvatar(): void
    {
        $user = Auth::user();

        if ($user->avatar_url && str_starts_with($user->avatar_url, '/storage/avatars/')) {
            $previous = str_replace('/storage/', '', $user->avatar_url);
            Storage::disk('public')->delete($previous);
        }

        $user->update(['avatar_url' => null]);

        $this->dispatch('avatar-updated');
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }

    /**
     * Flip one notification preference and persist all five immediately.
     */
    public function toggleNotification(string $key): void
    {
        $map = [
            'email'     => 'notifyEmail',
            'push'      => 'notifyPush',
            'marketing' => 'notifyMarketing',
            'orders'    => 'notifyOrders',
            'wallet'    => 'notifyWallet',
            'security'  => 'notifySecurity',
        ];

        if (! isset($map[$key])) {
            return;
        }

        $property = $map[$key];
        $this->{$property} = ! $this->{$property};

        app(\App\Domain\Notification\Services\NotificationPreferenceService::class)
            ->updatePreferences(Auth::user(), [
                'email_enabled'          => $this->notifyEmail,
                'push_enabled'           => $this->notifyPush,
                'marketing_enabled'      => $this->notifyMarketing,
                'order_notifications'    => $this->notifyOrders,
                'wallet_notifications'   => $this->notifyWallet,
                'security_notifications' => $this->notifySecurity,
            ]);

        $this->dispatch('preferences-saved');
    }
}; ?>

@php
    $authUser = auth()->user();
    $hasAvatar = !empty($authUser?->avatar_url);
    $emailVerified = (bool) $authUser?->email_verified_at;
    // 2FA + last login — backend hooks not shipped yet, flag for later wiring.
    $twoFactorEnabled = false;
    // Identity verification — backend hook: $user->kyc_status. Column not shipped yet,
    // so this resolves to null and the user shows as not identity-verified.
    $identityVerified = ($authUser?->kyc_status ?? null) === 'verified';
    $googleConnected = !empty($authUser?->google_id);
    $lastLoginAt = $authUser?->updated_at;
    $firstName = $authUser?->name ? str($authUser->name)->before(' ') : 'there';
    // Initials avatar fallback when no real photo is set. Deterministic per
    // user, so it stays stable across renders.
    $defaultAvatar = $authUser?->initialsAvatar() ?? '';
@endphp

<div
    x-data="{
        editingProfile: false,
        uploading: false,
        country: 'Cameroon',
        countryFlag: '🇨🇲',
        countryCode: 'cm',
        language: 'English',
        gender: @js($authUser?->gender ?? ''),
        genderMenuOpen: false,
        loaded: false,
        pickGender(g) { this.gender = g; this.genderMenuOpen = false; },
        init() {
            this.$nextTick(() => { this.loaded = true; });
        }
    }"
    x-on:locale-updated.window="country = $event.detail.country; countryFlag = $event.detail.countryFlag; countryCode = $event.detail.countryCode; language = $event.detail.language"
    x-on:livewire-upload-start="uploading = true"
    x-on:livewire-upload-finish="uploading = false"
    x-on:livewire-upload-error="uploading = false"
    x-on:profile-updated.window="editingProfile = false"
    class="flex w-full flex-col gap-6 pb-4"
>
    {{-- Desktop heading. On mobile the layout's sticky top bar shows the title, so this stays hidden there. --}}
    <div class="hidden items-center justify-between gap-3 lg:flex">
        <div>
            <h1 class="text-3xl font-bold tracking-tight text-black">Settings</h1>
            <p class="mt-1 text-[13px] text-zinc-600">Manage your account, security and preferences.</p>
        </div>
        <x-action-message on="profile-updated" class="text-xs font-medium text-emerald-600">
            Saved
        </x-action-message>
    </div>

    {{-- ─── Section 1: Personal Information ─── --}}
    <section>
        <div class="hidden mb-2.5 items-center justify-between lg:flex">
            <h2 class="text-base font-bold text-zinc-900">Personal Information</h2>
        </div>

        <div class="overflow-hidden rounded-[12px] bg-[#eff6ff] dash-shimmer border border-zinc-200 shadow-md shadow-zinc-900/[0.06] transition-colors hover:border-green-200 dark:border-zinc-700 dark:hover:border-white dark:shadow-none">
            {{-- Hero strip: avatar + name/email + edit toggle --}}
            <div class="relative px-5 pt-6 pb-5 sm:px-6">
                <div class="flex items-center gap-4 sm:gap-5">
                    {{-- Avatar with subtle ring + upload (cropper-driven).
                         Cropper.js loads from CDN at the bottom of this file.
                         File picker opens a square-crop modal that handles
                         upload + save in one shot. --}}
                    <div class="relative"
                        x-data="avatarCropper()"
                        @keydown.escape.window="cropperOpen && closeCropper()"
                    >
                        <span class="relative flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-full bg-blue-50 ring-4 ring-white shadow-sm shadow-blue-600/10">
                            <img src="{{ $hasAvatar ? $authUser->avatar_url : $defaultAvatar }}" alt="{{ $authUser?->name ?? 'Account' }}" class="h-full w-full object-cover">

                            <span x-show="uploading || saving" x-cloak class="absolute inset-0 flex items-center justify-center bg-white/75 backdrop-blur-sm">
                                <svg class="h-6 w-6 animate-spin text-blue-600" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" stroke-dasharray="50 50" stroke-linecap="round"/>
                                </svg>
                            </span>
                        </span>

                        <button type="button" @click="$refs.fileInput.click()" class="absolute -bottom-1 -right-1 flex h-7 w-7 cursor-pointer items-center justify-center rounded-full bg-blue-600 text-white shadow-sm shadow-blue-600/30 ring-2 ring-white transition-transform hover:scale-105 active:scale-95" aria-label="Upload profile photo">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316zM16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0z"/>
                            </svg>
                        </button>

                        <input x-ref="fileInput" @change="onFileChange" type="file" accept="image/png,image/jpeg,image/webp,image/gif" class="hidden">

                        {{-- Cropper modal — opens when a file is picked --}}
                        <template x-if="cropperOpen">
                            <div class="fixed inset-0 z-[80] flex items-center justify-center p-4">
                                <div class="absolute inset-0 bg-zinc-900/45" @click="!saving && closeCropper()" aria-hidden="true"></div>
                                <div class="relative w-full max-w-lg rounded-[12px] bg-white/70 ring-1 ring-white/40 p-5 shadow-2xl shadow-zinc-900/25 backdrop-blur-2xl backdrop-saturate-150 dark:bg-[#0c1a36]/70 dark:ring-white/10" role="dialog" aria-modal="true">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <h3 class="text-base font-bold text-zinc-900 dark:text-white">Crop your photo</h3>
                                            <p class="mt-0.5 text-xs text-zinc-600 dark:text-zinc-300">Drag the box, zoom with the corners. Square crop saves at 512×512.</p>
                                        </div>
                                        <x-close-button @click="closeCropper()" :disabled="false" />
                                    </div>

                                    <div class="mt-4 overflow-hidden rounded-[12px] bg-zinc-100 dark:bg-white/5" style="max-height: 60vh;">
                                        {{-- Cropper.js mutates this <img>; it must be inside a sized container. --}}
                                        <img x-ref="cropperImage" :src="imageSrc" alt="" class="block max-w-full" style="max-height: 60vh;">
                                    </div>

                                    <div class="mt-4 flex flex-wrap items-center gap-2">
                                        <button type="button" @click="closeCropper()" :disabled="saving" class="flex-1 rounded-[12px] border border-zinc-200 bg-white px-4 py-2.5 text-sm font-semibold text-zinc-700 transition-colors hover:bg-zinc-50 disabled:opacity-60 dark:border-white/15 dark:bg-white/10 dark:text-white dark:hover:bg-white/15">Cancel</button>
                                        <button type="button" @click="saveCrop()" :disabled="saving" class="flex-1 inline-flex items-center justify-center gap-1.5 rounded-[12px] bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-blue-700 disabled:opacity-60">
                                            <svg x-show="saving" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                                            </svg>
                                            <span x-show="!saving">Save photo</span>
                                            <span x-show="saving">Uploading…</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>

                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-1.5">
                            <p class="min-w-0 truncate text-lg font-bold text-black">{{ $authUser?->name ?? 'Account holder' }}</p>
                            {{-- Verification badge: ID-verified shows the Facebook-style
                                 blue tick; otherwise email-verified > basic pills. --}}
                            @if ($identityVerified)
                                {{-- -mt-0.5 lifts the seal to sit optically centered
                                     against the name's cap height. --}}
                                <a href="{{ route('dashboard.kyc') }}" wire:navigate title="Identity verified" class="-mt-0.5 shrink-0">
                                    <x-ui.verified-badge class="h-5 w-5" />
                                </a>
                            @elseif ($emailVerified)
                                <span class="inline-flex items-center rounded-[5px] bg-emerald-500 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white">Email Verified</span>
                            @else
                                <a href="{{ route('dashboard.kyc') }}" wire:navigate class="inline-flex items-center rounded-[5px] bg-zinc-500 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white dark:bg-zinc-700 dark:text-zinc-200">Basic</a>
                            @endif
                        </div>
                        <p class="mt-0.5 truncate text-[13px] text-zinc-600">Member since {{ $authUser?->created_at?->format('F Y') ?? '—' }}</p>

                        {{-- Remove photo (cropper handles upload+save in one shot, so
                             the previous "Save photo / Cancel" pair lives inside the modal). --}}
                        @if ($hasAvatar)
                            <button type="button" wire:click="removeAvatar" class="mt-2 text-xs font-medium text-red-600 transition-colors hover:text-red-700">Remove photo</button>
                        @endif
                    </div>

                    {{-- Edit profile button --}}
                    <button
                        type="button"
                        @click="editingProfile = !editingProfile"
                        class="hidden shrink-0 items-center gap-1.5 self-start rounded-[12px] border border-zinc-200 px-3.5 py-2 text-xs font-semibold text-zinc-700 transition-all hover:border-blue-600 hover:text-blue-700 active:scale-95 sm:inline-flex"
                        :class="editingProfile && 'border-blue-600 text-blue-700'"
                    >
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M12 18.75h7.5"/>
                        </svg>
                        <span x-text="editingProfile ? 'Done' : 'Edit profile'">Edit profile</span>
                    </button>
                </div>
                @error('avatar') <p class="mt-3 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            {{-- Detail rows --}}
            <div class="divide-inset">
                {{-- Full name --}}
                <div class="flex items-center justify-between gap-3 px-5 py-3.5 transition-colors hover:bg-zinc-50/60 dark:hover:bg-white/5 sm:px-6">
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-zinc-600">Full name</p>
                        <p class="mt-0.5 truncate text-sm font-medium text-black">{{ $authUser?->name ?? '—' }}</p>
                    </div>
                    <button type="button" @click="editingProfile = true" class="shrink-0 text-zinc-600 transition-colors hover:text-blue-600" aria-label="Edit full name">
                        <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/>
                        </svg>
                    </button>
                </div>

                {{-- Email --}}
                <div class="flex items-center justify-between gap-3 px-5 py-3.5 transition-colors hover:bg-zinc-50/60 dark:hover:bg-white/5 sm:px-6">
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-zinc-600">Email address</p>
                        <p class="mt-0.5 truncate text-sm font-medium text-black">{{ $authUser?->email ?? '—' }}</p>
                    </div>
                    @if ($emailVerified)
                        <span class="inline-flex items-center gap-1 rounded-[5px] bg-emerald-500 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white">Verified</span>
                    @else
                        <button wire:click="resendVerificationNotification" class="rounded-[5px] bg-amber-500 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white transition-colors hover:bg-amber-600">Verify</button>
                    @endif
                </div>

                {{-- Phone --}}
                <button type="button" @click="editingProfile = true" class="flex w-full items-center justify-between gap-3 px-5 py-3.5 text-left transition-colors hover:bg-zinc-50/60 dark:hover:bg-white/5 sm:px-6">
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-zinc-600">Phone number</p>
                        @if (! empty($phone))
                            <p class="mt-0.5 text-sm font-medium text-black">{{ $phone }}</p>
                        @else
                            <p class="mt-0.5 text-[13px] font-medium text-zinc-600 italic">Not set</p>
                        @endif
                    </div>
                    <span class="shrink-0 text-zinc-600" aria-label="Edit phone">
                        <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/>
                        </svg>
                    </span>
                </button>

                {{-- Gender --}}
                <button type="button" @click="editingProfile = true" class="flex w-full items-center justify-between gap-3 px-5 py-3.5 text-left transition-colors hover:bg-zinc-50/60 dark:hover:bg-white/5 sm:px-6">
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-zinc-600">Gender</p>
                        @if (! empty($gender))
                            <p class="mt-0.5 text-sm font-medium text-black">{{ ucfirst($gender) }}</p>
                        @else
                            <p class="mt-0.5 text-[13px] font-medium text-zinc-600 italic">Not set</p>
                        @endif
                    </div>
                    <span class="shrink-0 text-zinc-600" aria-label="Edit gender">
                        <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/>
                        </svg>
                    </span>
                </button>

                {{-- Country (account attribute) — the user's declared country,
                     shown on their profile and to admins. Edited via the form
                     below; this is NOT the shopping region switcher. --}}
                <div class="flex w-full items-center justify-between gap-3 px-5 py-3.5 text-left transition-colors hover:bg-zinc-50/60 dark:hover:bg-white/5 sm:px-6">
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-zinc-600">Country</p>
                        <p class="mt-0.5 flex items-center gap-2 text-sm font-medium text-black dark:text-white">
                            @php $profileCc = strtolower((string) (config('countries.codes')[$country] ?? '')); @endphp
                            @if ($profileCc)
                                <img src="https://flagcdn.com/w40/{{ $profileCc }}.png" alt="" class="h-3.5 w-5 shrink-0 rounded-[2px] object-cover ring-1 ring-zinc-200">
                            @endif
                            <span>{{ $country ?: 'Not set' }}</span>
                        </p>
                    </div>
                </div>
            </div>

            {{-- Mobile edit button (full width at bottom of card on small screens) --}}
            <div class="border-t border-zinc-100 p-4 sm:hidden">
                <button
                    type="button"
                    @click="editingProfile = !editingProfile"
                    class="flex w-full items-center justify-center gap-2 rounded-[12px] bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-blue-600/25 transition-all hover:bg-blue-700 active:scale-[0.98]"
                    :class="editingProfile && '!bg-blue-700'"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M12 18.75h7.5"/>
                    </svg>
                    <span x-text="editingProfile ? 'Close editor' : 'Edit profile'">Edit profile</span>
                </button>
            </div>

            {{-- Inline edit form (slides down) --}}
            <div
                x-show="editingProfile"
                x-cloak
                x-collapse
                class="border-t border-zinc-100 bg-zinc-50/60 dark:border-white/10 dark:bg-white/[0.03]"
            >
                <form wire:submit="updateProfileInformation" class="space-y-4 p-5 sm:p-6">
                    <div>
                        <label for="profile-name" class="mb-1.5 block text-xs font-semibold text-zinc-700 dark:text-zinc-300">Full name</label>
                        <input
                            wire:model="name"
                            id="profile-name"
                            type="text"
                            required
                            class="w-full rounded-[12px] border border-zinc-300 bg-white px-3.5 py-2.5 text-sm text-black outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-white/15 dark:bg-[#0c1a36] dark:text-white"
                            placeholder="Your full name"
                        />
                        @error('name') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="profile-email" class="mb-1.5 block text-xs font-semibold text-zinc-700 dark:text-zinc-300">Email address</label>
                        <input
                            wire:model="email"
                            id="profile-email"
                            type="email"
                            required
                            class="w-full rounded-[12px] border border-zinc-300 bg-white px-3.5 py-2.5 text-sm text-black outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-white/15 dark:bg-[#0c1a36] dark:text-white"
                            placeholder="you@example.com"
                        />
                        @error('email') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="profile-phone" class="mb-1.5 block text-xs font-semibold text-zinc-700 dark:text-zinc-300">Phone number</label>
                        <input
                            wire:model="phone"
                            id="profile-phone"
                            type="tel"
                            inputmode="tel"
                            autocomplete="tel"
                            class="w-full rounded-[12px] border border-zinc-300 bg-white px-3.5 py-2.5 text-sm text-black outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-white/15 dark:bg-[#0c1a36] dark:text-white dark:placeholder:text-zinc-500"
                            placeholder="+237 6XX XXX XXX"
                        />
                        @error('phone') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    @php
                        // Same option shape the KYC dropdown consumes, so the country
                        // field reuses the shared kycSelect modern dropdown component.
                        $countryOptions = collect(array_keys(config('countries.codes', [])))
                            ->map(fn ($cName) => ['value' => $cName, 'label' => $cName])
                            ->values();
                    @endphp
                    <div>
                        <label for="profile-country" class="mb-1.5 block text-xs font-semibold text-zinc-700 dark:text-zinc-300">Country</label>
                        <div
                            x-data="kycSelect(@js($countryOptions), @js($country), true)"
                            x-init="$watch('value', value => $wire.set('country', value, false))"
                            @click.outside="open = false"
                            @keydown.escape="open = false"
                            class="relative"
                        >
                            <button
                                type="button"
                                id="profile-country"
                                @click="toggle()"
                                :class="open && 'border-blue-500 ring-2 ring-blue-500/15'"
                                class="flex w-full items-center justify-between gap-2 rounded-[12px] border border-zinc-300 bg-white px-3.5 py-2.5 text-left text-sm text-black transition-colors dark:border-white/15 dark:bg-[#0c1a36] dark:text-white"
                            >
                                <span :class="value ? 'text-black dark:text-white' : 'text-zinc-400'" x-text="selectedLabel || 'Select your country'"></span>
                                <svg :class="open && 'rotate-180'" class="h-4 w-4 shrink-0 text-zinc-500 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/>
                                </svg>
                            </button>

                            <div
                                x-show="open"
                                x-cloak
                                x-transition:enter="transition ease-out duration-150"
                                x-transition:enter-start="opacity-0 -translate-y-1"
                                x-transition:enter-end="opacity-100 translate-y-0"
                                class="absolute left-0 z-30 mt-1 w-full overflow-hidden rounded-[12px] bg-[#eff6ff] shadow-lg shadow-zinc-900/10 ring-1 ring-zinc-200 dark:bg-[#0c1a36] dark:ring-white/15"
                            >
                                <div class="border-b border-zinc-100 p-2 dark:border-white/10">
                                    <input
                                        x-ref="search"
                                        x-model="query"
                                        type="text"
                                        placeholder="Search countries"
                                        class="w-full rounded-[12px] border border-zinc-200 bg-white px-3 py-1.5 text-sm text-zinc-900 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-white/15 dark:bg-[#0c1a36] dark:text-white"
                                    >
                                </div>
                                <ul class="max-h-52 overflow-y-auto p-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                                    <template x-for="opt in filtered" :key="opt.value">
                                        <li>
                                            <button
                                                type="button"
                                                @click="pick(opt.value)"
                                                :class="opt.value === value ? 'bg-blue-50 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300' : 'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5'"
                                                class="flex w-full items-center justify-between gap-2 rounded-[12px] px-3 py-2 text-left text-sm transition-colors"
                                            >
                                                <span x-text="opt.label"></span>
                                                <svg x-show="opt.value === value" class="h-4 w-4 shrink-0 text-blue-600 dark:text-blue-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                                </svg>
                                            </button>
                                        </li>
                                    </template>
                                    <li x-show="filtered.length === 0" class="px-3 py-3 text-center text-sm text-zinc-500">No matches</li>
                                </ul>
                            </div>
                        </div>
                        @error('country') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        <p class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400">The country shown on your account - this does not change the shop region.</p>
                    </div>

                    {{-- Gender pills. `g` is entangled with the Livewire `gender`
                         property so the highlight flips instantly client-side on
                         click (no per-click server round-trip) and still syncs for
                         the Save. --}}
                    <div x-data="{ g: @entangle('gender') }">
                        <span class="mb-1.5 block text-xs font-semibold text-zinc-700 dark:text-zinc-300">Gender</span>
                        <div class="grid grid-cols-3 gap-2">
                            @foreach ([
                                ['value' => 'male',   'label' => 'Male',   'icon' => 'male.svg'],
                                ['value' => 'female', 'label' => 'Female', 'icon' => 'female.svg'],
                                ['value' => 'other',  'label' => 'Other',  'icon' => 'other.svg'],
                            ] as $opt)
                                <button
                                    type="button"
                                    @click="g = '{{ $opt['value'] }}'"
                                    :aria-pressed="(g === '{{ $opt['value'] }}').toString()"
                                    :class="g === '{{ $opt['value'] }}'
                                        ? 'border-blue-600 bg-blue-50 text-blue-700 dark:border-blue-400 dark:bg-blue-500/15 dark:text-blue-300'
                                        : 'border-zinc-300 bg-white text-zinc-700 hover:border-zinc-400 dark:border-white/15 dark:bg-[#0c1a36] dark:text-zinc-200 dark:hover:border-white/30'"
                                    class="flex flex-col items-center gap-1 rounded-full border px-2 py-2.5 text-xs font-semibold transition-all active:scale-95"
                                >
                                    <img src="{{ asset('assets/' . $opt['icon']) }}" alt="" class="h-5 w-5 dark:brightness-0 dark:invert" loading="lazy">
                                    {{ $opt['label'] }}
                                </button>
                            @endforeach
                        </div>
                        @error('gender') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex items-center gap-2 pt-1">
                        <button type="submit" class="inline-flex items-center gap-2 rounded-[12px] bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-blue-600/25 transition-all hover:bg-blue-700 active:scale-[0.98]">
                            <span wire:loading.remove wire:target="updateProfileInformation">Save changes</span>
                            <span wire:loading wire:target="updateProfileInformation" class="inline-flex items-center gap-2">
                                <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" stroke-dasharray="50 50" stroke-linecap="round"/>
                                </svg>
                                Saving
                            </span>
                        </button>
                        <button type="button" @click="editingProfile = false" class="rounded-[12px] border border-zinc-200 bg-white px-4 py-2.5 text-sm font-semibold text-zinc-700 transition-colors hover:bg-zinc-50 dark:border-white/15 dark:bg-white/10 dark:text-white dark:hover:bg-white/15">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </section>

    {{-- ─── Section 2: Security ─── --}}
    <section>
        <h2 class="mb-2.5 text-base font-bold text-zinc-900">Security</h2>

        <div class="flex flex-col gap-3">
            {{-- Password + Google on one card --}}
            <div class="divide-inset overflow-hidden rounded-[12px] bg-[#eff6ff] dash-shimmer border border-zinc-200 shadow-md shadow-zinc-900/[0.06] transition-colors hover:border-green-200 dark:border-zinc-700 dark:hover:border-white dark:shadow-none">
            <a href="{{ route('dashboard.password') }}" wire:navigate class="relative flex items-center gap-4 p-4 transition-colors hover:bg-zinc-50/60 dark:hover:bg-white/5 sm:p-5">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-[12px] bg-[#0a1729] text-white">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/>
                    </svg>
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-black">Password</p>
                    <p class="mt-0.5 text-[13px] text-zinc-600">Change your account password</p>
                </div>
                <svg class="h-5 w-5 shrink-0 text-zinc-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/>
                </svg>
            </a>

            {{-- Google connection row --}}
            <div class="flex items-center gap-4 p-4 transition-colors hover:bg-zinc-50/60 dark:hover:bg-white/5 sm:p-5">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-[12px] bg-white ring-1 ring-zinc-200">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" aria-hidden="true">
                        <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.76h3.56c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                        <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.56-2.76c-.98.66-2.24 1.06-3.72 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84A11 11 0 0012 23z"/>
                        <path fill="#FBBC05" d="M5.84 14.11A6.61 6.61 0 015.5 12c0-.73.13-1.44.34-2.11V7.05H2.18A11 11 0 001 12c0 1.78.43 3.46 1.18 4.95l3.66-2.84z"/>
                        <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.05l3.66 2.84C6.71 7.31 9.14 5.38 12 5.38z"/>
                    </svg>
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-black">Google account</p>
                    <p class="mt-0.5 text-[13px] text-zinc-600">
                        @if ($googleConnected)
                            Connected to {{ $authUser?->email }}
                        @else
                            Sign in faster by linking Google
                        @endif
                    </p>
                </div>
                @if ($googleConnected)
                    <span class="inline-flex items-center gap-1 rounded-[5px] bg-emerald-500 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white">
                        <svg class="h-2.5 w-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                        </svg>
                        Connected
                    </span>
                @else
                    <a
                        x-data
                        href="{{ route('auth.google.redirect', ['popup' => 1]) }}"
                        @click.prevent="window.rshopOpenGoogleOAuth ? window.rshopOpenGoogleOAuth($el.href) : (window.location.href = $el.href)"
                        class="rounded-[12px] border border-zinc-200 bg-white px-3 py-1.5 text-xs font-semibold text-zinc-700 transition-colors hover:bg-zinc-50"
                    >Connect</a>
                @endif
            </div>
            </div>

            {{-- Last login activity (small text) --}}
            <div class="flex items-center gap-2.5 px-1 text-[13px] text-zinc-600">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                Last active {{ $lastLoginAt?->diffForHumans() ?? 'recently' }} on this device
            </div>
        </div>
    </section>

    {{-- Theme picking lives on the dedicated Appearance page
         (dashboard/appearance) - not duplicated here. --}}

    {{-- ─── Section 4: Notifications ─── --}}
    <section x-data x-init="$watch('$wire.notifyPush', val => { if(val) window.RshopPush?.subscribe(); else window.RshopPush?.unsubscribe(); })">
        <h2 class="mb-2.5 text-base font-bold text-zinc-900">Notifications</h2>

        <div class="divide-inset overflow-hidden rounded-[12px] bg-[#eff6ff] dash-shimmer border border-zinc-200 shadow-md shadow-zinc-900/[0.06] transition-colors hover:border-green-200 dark:border-zinc-700 dark:hover:border-white dark:shadow-none">
            @php
                // [key, current value, label, description, heroicon path]
                $notifyRows = [
                    ['push',      $notifyPush,      'Web Push',            'Real-time push notifications',       'M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0'],
                    ['orders',    $notifyOrders,    'Order updates',       'Delivery, fulfillment and refunds',  'M15.75 10.5V6a3.75 3.75 0 10-7.5 0v4.5m11.356-1.993l1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 01-1.12-1.243l1.264-12A1.125 1.125 0 015.513 7.5h12.974c.576 0 1.059.435 1.119 1.007z'],
                    ['wallet',    $notifyWallet,    'Wallet activity',     'Funding, credits and debits',        'M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z'],
                    ['security',  $notifySecurity,  'Security alerts',     'Login attempts and account changes', 'M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z'],
                    ['marketing', $notifyMarketing, 'Promotions',          'Deals, offers and new arrivals',     'M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z'],
                    ['email',     $notifyEmail,     'Email notifications', 'Master switch for all emails',       'M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75'],
                ];
            @endphp
            @foreach ($notifyRows as [$key, $on, $label, $desc, $iconPath])
                <div class="flex items-center justify-between gap-3 px-5 py-4 sm:px-6">
                    <span class="flex items-center gap-3">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-[12px] bg-[#0a1729] text-white">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $iconPath }}"/>
                            </svg>
                        </span>
                        <span>
                            <span class="block text-sm font-semibold text-black">{{ $label }}</span>
                            <span class="block text-[13px] text-zinc-600">{{ $desc }}</span>
                        </span>
                    </span>
                    <button
                        type="button"
                        role="switch"
                        aria-checked="{{ $on ? 'true' : 'false' }}"
                        aria-label="{{ $label }}"
                        wire:click="toggleNotification('{{ $key }}')"
                        class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-[12px] transition-colors duration-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 {{ $on ? 'bg-blue-600' : 'bg-zinc-200 dark:bg-zinc-700' }}"
                    >
                        <span class="theme-static pointer-events-none absolute top-0.5 inline-block h-5 w-5 rounded-[12px] bg-white shadow ring-0 transition-transform duration-300 {{ $on ? 'translate-x-5' : 'translate-x-0.5' }}"></span>
                    </button>
                </div>
            @endforeach
        </div>

        <p class="mt-2.5 flex items-center gap-2 px-1 text-[13px] text-zinc-600">
            Changes save automatically.
            <x-action-message on="preferences-saved" class="font-medium text-emerald-600">Saved.</x-action-message>
        </p>
    </section>

    {{-- ─── Section 5: Danger Zone ─── --}}
    <section class="mt-2">
        <h2 class="mb-2.5 text-base font-bold text-red-600">Danger zone</h2>

        <div class="divide-inset overflow-hidden rounded-[12px] bg-[#eff6ff] dash-shimmer border border-zinc-200 shadow-md shadow-zinc-900/[0.06] transition-colors hover:border-green-200 dark:border-zinc-700 dark:hover:border-white dark:shadow-none">
            {{-- Logout --}}
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button
                    type="submit"
                    class="flex w-full items-center justify-between gap-3 px-5 py-4 text-left sm:px-6"
                >
                    <span class="flex items-center gap-3">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-[12px] bg-[#0a1729] text-white">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75"/>
                            </svg>
                        </span>
                        <span>
                            <span class="block text-sm font-semibold text-black">Log out</span>
                            <span class="block text-[13px] text-zinc-600">End your session on this device</span>
                        </span>
                    </span>
                    <svg class="h-5 w-5 shrink-0 text-zinc-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/>
                    </svg>
                </button>
            </form>

            {{-- Delete account — subtle entry point, opens the existing modal flow --}}
            <div class="px-5 py-4 sm:px-6">
                <livewire:settings.delete-user-form />
            </div>
        </div>
    </section>

    {{-- Cropper.js — loaded from CDN. ~30 KB gz, only on this settings page.
         The `avatarCropper()` Alpine component (defined below) opens a
         square-crop modal when a file is picked, then uploads the cropped
         512x512 JPEG via Livewire's programmatic upload API and triggers
         updateAvatar() to persist.
         These tags MUST sit inside the component's single root <div>
         (Livewire requirement). The browser still hoists/handles them. --}}
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/cropperjs@1.6.1/dist/cropper.min.css">
    <script defer src="https://cdn.jsdelivr.net/npm/cropperjs@1.6.1/dist/cropper.min.js"></script>
    <script>
        window.avatarCropper = function () {
        return {
            cropperOpen: false,
            imageSrc: null,
            cropper: null,
            saving: false,

            onFileChange(event) {
                const file = event.target.files && event.target.files[0];
                if (! file) {
                    return;
                }
                const reader = new FileReader();
                reader.onload = (e) => {
                    this.imageSrc = e.target.result;
                    this.cropperOpen = true;
                    this.$nextTick(() => this.initCropper());
                };
                reader.readAsDataURL(file);
                // Reset so picking the same file again still fires @change.
                event.target.value = '';
            },

            initCropper() {
                if (typeof Cropper === 'undefined') {
                    alert('Cropper failed to load. Check your network and try again.');
                    this.closeCropper();
                    return;
                }
                const img = this.$refs.cropperImage;
                this.cropper = new Cropper(img, {
                    aspectRatio: 1,
                    viewMode: 1,
                    autoCropArea: 1,
                    background: false,
                    guides: true,
                    center: true,
                    movable: true,
                    zoomable: true,
                    rotatable: false,
                    scalable: false,
                });
            },

            closeCropper() {
                if (this.cropper) {
                    this.cropper.destroy();
                    this.cropper = null;
                }
                this.imageSrc = null;
                this.cropperOpen = false;
            },

            saveCrop() {
                if (! this.cropper || this.saving) {
                    return;
                }
                // $wire is a magic on the Alpine instance (provided by Livewire). Capture it
                // here so the nested callbacks below see a stable reference; using bare
                // `$wire` inside method bodies (vs HTML expressions) is undefined and throws.
                const wire = this.$wire;
                if (! wire) {
                    alert('Livewire not initialised on this page. Refresh and try again.');
                    return;
                }
                this.saving = true;
                const canvas = this.cropper.getCroppedCanvas({
                    width: 512,
                    height: 512,
                    imageSmoothingEnabled: true,
                    imageSmoothingQuality: 'high',
                });
                canvas.toBlob((blob) => {
                    if (! blob) {
                        this.saving = false;
                        alert('Could not produce the cropped image.');
                        return;
                    }
                    // Wrap the blob in a File so Livewire/PHP sees it with a name + mime.
                    const file = new File([blob], 'avatar.jpg', { type: 'image/jpeg' });

                    wire.upload('avatar', file, () => {
                        // Upload finished. Persist on the User. updateAvatar() can throw
                        // server-side (validation, DB) — catch so the modal isn't stuck.
                        wire.call('updateAvatar')
                            .then(() => {
                                this.saving = false;
                                this.closeCropper();
                            })
                            .catch((err) => {
                                console.error('updateAvatar failed:', err);
                                this.saving = false;
                                alert('Save failed. ' + (err && err.message ? err.message : 'Please try again.'));
                            });
                    }, (err) => {
                        console.error('avatar upload failed:', err);
                        this.saving = false;
                        alert('Upload failed. Please try again.');
                    });
                }, 'image/jpeg', 0.92);
            },
        };
    };
    </script>
</div>
