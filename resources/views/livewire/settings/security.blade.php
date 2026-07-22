<?php

use App\Models\TrustedDevice;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use App\Livewire\Traits\EnforcesDeviceTrust;

new #[Layout('components.layouts.dashboard')] class extends Component {
    use EnforcesDeviceTrust;

    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';
    
    public string $emailOtpPreference = '';

    public function mount(): void
    {
        $this->emailOtpPreference = Auth::user()->email_otp_preference ?? 'always';
    }

    /**
     * Update the password for the currently authenticated user.
     */
    public function updatePassword(): void
    {
        $this->enforceDeviceTrust('current_password');

        try {
            $validated = $this->validate([
                'current_password' => ['required', 'string', 'current_password'],
                'password' => ['required', 'string', Password::defaults(), 'confirmed'],
            ]);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        Auth::user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        $this->reset('current_password', 'password', 'password_confirmation');

        \App\Domain\Security\Events\PasswordChanged::dispatch(Auth::user());
        
        $this->dispatch('toast', message: 'Your password has been updated.');
    }
    
    public function setOtpPreference($value): void
    {
        $this->enforceDeviceTrust('emailOtpPreference');

        $user = Auth::user();
        $user->email_otp_preference = $value;
        $user->save();
        
        $this->emailOtpPreference = $value;
        $this->dispatch('toast', message: 'Preferences updated.');
    }

    public function revokeDevice($deviceId): void
    {
        $this->enforceDeviceTrust('device-' . $deviceId);

        $device = TrustedDevice::where('id', $deviceId)->where('user_id', Auth::id())->first();
        if ($device) {
            $device->sessions()->update(['revoked_at' => now()]);
            $device->trusted_since = null;
            $device->save();
        }
    }
    
    public function with(): array
    {
        $devices = TrustedDevice::where('user_id', Auth::id())
            ->whereNotNull('trusted_since') // Only show active devices
            ->orderByDesc('last_seen_at')
            ->get();
            
        // Auto-repair retroactively for existing devices before Agent package was installed
        foreach ($devices as $device) {
            if (empty($device->os) || str_contains($device->os, 'Unknown') || $device->os === '0') {
                $agent = new \Jenssegers\Agent\Agent();
                $agent->setUserAgent($device->user_agent);
                $device->os = $agent->platform() ?: 'Unknown OS';
                $device->browser = $agent->browser() ?: 'Unknown Browser';
                $device->device_type = $agent->isDesktop() ? 'desktop' : ($agent->isTablet() ? 'tablet' : 'mobile');
                $device->save();
            }
        }
        
        $currentSessionId = session()->getId();
        $userSession = \App\Models\UserSession::where('laravel_session_id', $currentSessionId)->first();
        
        if ($userSession) {
            $currentDeviceId = $userSession->trusted_device_id;
        } else {
            // Robust fallback if laravel_session_id is mismatched
            $fallbackDevice = TrustedDevice::where('user_id', Auth::id())
                ->where('ip_address', request()->ip())
                ->where('user_agent', request()->userAgent())
                ->orderByDesc('last_seen_at')
                ->first();
            $currentDeviceId = $fallbackDevice?->id;
        }

        return [
            'devices' => $devices,
            'currentDeviceId' => $currentDeviceId,
        ];
    }
}; ?>

<div x-data="{ tab: 'password' }" class="flex w-full flex-col gap-6">
    {{-- Page heading --}}
    <div class="text-center sm:text-left">
        <h1 class="text-2xl font-bold tracking-tight text-black sm:text-3xl dark:text-white">Security Center</h1>
        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">Manage your passwords, PINs, and connected devices.</p>
    </div>

    {{-- Tabs Navigation --}}
    <div class="flex space-x-1 rounded-[12px] bg-zinc-100 p-1 dark:bg-[#070f1c]">
        <button type="button" @click="tab = 'password'" :class="{ 'bg-white text-black shadow-sm dark:bg-[#24364f] dark:text-white': tab === 'password', 'text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-300': tab !== 'password' }" class="flex-1 rounded-[10px] px-3 py-2 text-sm font-semibold transition-all focus:outline-none">
            Password
        </button>
        <button type="button" @click="tab = 'pin'" :class="{ 'bg-white text-black shadow-sm dark:bg-[#24364f] dark:text-white': tab === 'pin', 'text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-300': tab !== 'pin' }" class="flex-1 rounded-[10px] px-3 py-2 text-sm font-semibold transition-all focus:outline-none">
            Transaction PIN
        </button>
        <button type="button" @click="tab = 'preferences'" :class="{ 'bg-white text-black shadow-sm dark:bg-[#24364f] dark:text-white': tab === 'preferences', 'text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-300': tab !== 'preferences' }" class="flex-1 rounded-[10px] px-3 py-2 text-sm font-semibold transition-all focus:outline-none">
            Preferences & Devices
        </button>
    </div>

    {{-- Password Tab --}}
    <div x-show="tab === 'password'" x-cloak>
        <div class="rounded-[12px] bg-[#eff6ff] p-6 dash-shimmer border border-zinc-200 shadow-md shadow-zinc-900/[0.06] transition-colors hover:border-blue-200 dark:bg-[#0c1a36]/50 dark:border-zinc-700 dark:hover:border-white dark:shadow-none">
            <div class="mb-5">
                <h2 class="text-base font-semibold text-black dark:text-white">Change password</h2>
                <p class="mt-0.5 text-xs text-zinc-600 dark:text-zinc-400">You'll stay signed in on this device after updating.</p>
            </div>

            <form wire:submit="updatePassword" class="space-y-5">
                {{-- Current password --}}
                <div x-data="{ show: false }">
                    <div class="mb-1.5 flex items-center justify-between">
                        <label for="password-current" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('Current password') }}</label>
                        <a href="{{ route('password.request') }}" class="text-xs font-medium text-blue-600 hover:text-blue-700 hover:underline dark:text-blue-400 dark:hover:text-blue-300">Forgot Password?</a>
                    </div>
                    <div class="relative">
                        <input
                            wire:model="current_password"
                            id="password-current"
                            name="current_password"
                            :type="show ? 'text' : 'password'"
                            required
                            autocomplete="current-password"
                            placeholder="Enter your current password"
                            class="w-full rounded-[12px] border border-zinc-300 bg-white px-3.5 py-2.5 pr-11 text-sm text-black placeholder:text-zinc-600 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700 dark:bg-[#1d3252] dark:text-white dark:placeholder:text-zinc-400"
                        />
                        <button type="button" @click="show = !show" class="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-600 hover:text-zinc-600 dark:text-zinc-400" :aria-label="show ? 'Hide password' : 'Show password'">
                            <svg x-show="!show" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            </svg>
                            <svg x-show="show" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true" style="display:none;">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.244 7.244L19.5 19.5m-2.876-2.876L13.875 13.875M9.878 9.878a3 3 0 105.249 5.249"/>
                            </svg>
                        </button>
                    </div>
                    @error('current_password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                {{-- New password --}}
                <div x-data="{ show: false }">
                    <label for="password-new" class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('New password') }}</label>
                    <div class="relative">
                        <input
                            wire:model="password"
                            id="password-new"
                            name="password"
                            :type="show ? 'text' : 'password'"
                            required
                            autocomplete="new-password"
                            placeholder="At least 8 characters"
                            class="w-full rounded-[12px] border border-zinc-300 bg-white px-3.5 py-2.5 pr-11 text-sm text-black placeholder:text-zinc-600 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700 dark:bg-[#1d3252] dark:text-white dark:placeholder:text-zinc-400"
                        />
                        <button type="button" @click="show = !show" class="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-600 hover:text-zinc-600 dark:text-zinc-400" :aria-label="show ? 'Hide password' : 'Show password'">
                            <svg x-show="!show" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            </svg>
                            <svg x-show="show" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true" style="display:none;">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.244 7.244L19.5 19.5m-2.876-2.876L13.875 13.875M9.878 9.878a3 3 0 105.249 5.249"/>
                            </svg>
                        </button>
                    </div>
                    @error('password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                {{-- Confirm password --}}
                <div x-data="{ show: false }">
                    <label for="password-confirm" class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('Confirm new password') }}</label>
                    <div class="relative">
                        <input
                            wire:model="password_confirmation"
                            id="password-confirm"
                            name="password_confirmation"
                            :type="show ? 'text' : 'password'"
                            required
                            autocomplete="new-password"
                            placeholder="Re-enter your new password"
                            class="w-full rounded-[12px] border border-zinc-300 bg-white px-3.5 py-2.5 pr-11 text-sm text-black placeholder:text-zinc-600 outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700 dark:bg-[#1d3252] dark:text-white dark:placeholder:text-zinc-400"
                        />
                        <button type="button" @click="show = !show" class="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-600 hover:text-zinc-600 dark:text-zinc-400" :aria-label="show ? 'Hide password' : 'Show password'">
                            <svg x-show="!show" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            </svg>
                            <svg x-show="show" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true" style="display:none;">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.244 7.244L19.5 19.5m-2.876-2.876L13.875 13.875M9.878 9.878a3 3 0 105.249 5.249"/>
                            </svg>
                        </button>
                    </div>
                    @error('password_confirmation') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                {{-- Save action --}}
                <div class="flex items-center gap-3 pt-1">
                    <button type="submit" wire:loading.attr="disabled" class="inline-flex items-center gap-2 rounded-[12px] bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm shadow-blue-600/25 transition-colors hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/50 disabled:opacity-75 disabled:cursor-not-allowed">
                        <svg wire:loading wire:target="updatePassword" class="h-4 w-4 animate-spin text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span>{{ __('Update password') }}</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- PIN Tab --}}
    <div x-show="tab === 'pin'" x-cloak>
        <livewire:settings.transaction-pin />
    </div>

    {{-- Preferences & Devices Tab --}}
    <div x-show="tab === 'preferences'" x-cloak class="flex flex-col gap-6">
        
        <div class="rounded-[12px] bg-[#eff6ff] p-6 dash-shimmer border border-zinc-200 shadow-md shadow-zinc-900/[0.06] transition-colors hover:border-blue-200 dark:bg-[#0c1a36]/50 dark:border-zinc-700 dark:hover:border-white dark:shadow-none">
            <div class="mb-5">
                <h2 class="text-base font-semibold text-black dark:text-white">OTP Preferences</h2>
                <p class="mt-0.5 text-xs text-zinc-600 dark:text-zinc-400">Choose when you want to receive Email OTPs for logins.</p>
            </div>
            
            <div>
                <label for="otp-preference" class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Login Verification</label>
                <div class="relative max-w-sm" x-data="{ open: false }">
                    <button
                        type="button"
                        @click="open = !open"
                        @click.outside="open = false"
                        :class="open ? 'border-blue-500 ring-2 ring-blue-500/15' : 'border-zinc-300 dark:border-zinc-700 hover:border-zinc-400 dark:hover:border-zinc-500'"
                        class="flex w-full items-center justify-between gap-2 rounded-[12px] border bg-white px-3.5 py-2.5 text-sm text-black transition-colors outline-none dark:bg-[#1d3252] dark:text-white"
                    >
                        <span x-text="$wire.emailOtpPreference === 'always' ? 'Always Require OTP' : 'Only on New Devices'"></span>
                        <svg class="h-4 w-4 shrink-0 text-zinc-500 transition-transform duration-200" :class="{ 'rotate-180': open }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>
                    
                    <div
                        x-show="open"
                        x-cloak
                        x-transition:enter="transition ease-out duration-150"
                        x-transition:enter-start="opacity-0 -translate-y-1"
                        x-transition:enter-end="opacity-100 translate-y-0"
                        x-transition:leave="transition ease-in duration-100"
                        x-transition:leave-end="opacity-0"
                        class="absolute left-0 right-0 top-full z-20 mt-1 overflow-hidden rounded-[12px] border border-zinc-200 bg-white/80 p-1 shadow-xl shadow-zinc-900/10 backdrop-blur-xl dark:border-zinc-700 dark:bg-[#0c1a36]/90"
                    >
                        <button
                            type="button"
                            @click="$wire.setOtpPreference('new_device'); open = false"
                            :class="$wire.emailOtpPreference === 'new_device' ? 'bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400' : 'text-zinc-800 hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-zinc-800'"
                            class="flex w-full items-center justify-between rounded-[10px] px-3 py-2.5 text-left text-sm font-medium transition-colors"
                        >
                            Only on New Devices
                            <svg x-show="$wire.emailOtpPreference === 'new_device'" class="h-4 w-4 shrink-0 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                            </svg>
                        </button>
                        <button
                            type="button"
                            @click="$wire.setOtpPreference('always'); open = false"
                            :class="$wire.emailOtpPreference === 'always' ? 'bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400' : 'text-zinc-800 hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-zinc-800'"
                            class="mt-0.5 flex w-full items-center justify-between rounded-[10px] px-3 py-2.5 text-left text-sm font-medium transition-colors"
                        >
                            Always Require OTP
                            <svg x-show="$wire.emailOtpPreference === 'always'" class="h-4 w-4 shrink-0 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="rounded-[12px] bg-[#eff6ff] p-6 dash-shimmer border border-zinc-200 shadow-md shadow-zinc-900/[0.06] transition-colors hover:border-blue-200 dark:bg-[#0c1a36]/50 dark:border-zinc-700 dark:hover:border-white dark:shadow-none">
            <div class="mb-5">
                <h2 class="text-base font-semibold text-black dark:text-white">Connected Devices</h2>
                <p class="mt-0.5 text-xs text-zinc-600 dark:text-zinc-400">Active sessions on your account. Revoke any you don't recognize.</p>
            </div>
            
            @if($devices->isEmpty())
                <p class="text-sm text-zinc-500 dark:text-zinc-400">No connected devices found.</p>
            @else
                <ul class="divide-y divide-zinc-200 dark:divide-zinc-700/60">
                    @foreach($devices as $device)
                        <li class="py-4 flex justify-between items-center">
                            <div class="flex items-center gap-3">
                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[10px] bg-blue-100 text-blue-600 dark:bg-blue-500/20 dark:text-blue-300">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                    </svg>
                                </div>
                                <div>
                                    <p class="text-sm font-semibold text-black dark:text-white">{{ $device->os ?? 'Unknown OS' }} &middot; {{ $device->browser ?? 'Unknown Browser' }}</p>
                                    <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $device->ip_address }} @if($device->city) &middot; {{ $device->city }} @endif</p>
                                    <p class="mt-0.5 text-[11px] text-zinc-400">Last login: {{ $device->last_seen_at?->diffForHumans() ?? 'Never' }}</p>
                                </div>
                            </div>
                            <div>
                                @if($device->id === $currentDeviceId)
                                    <span class="inline-flex items-center rounded-[8px] bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400 dark:ring-emerald-500/20">Current Device</span>
                                @else
                                    <button type="button" wire:click="revokeDevice({{ $device->id }})" class="rounded-[8px] px-3 py-1.5 text-xs font-semibold text-red-600 transition-colors hover:bg-red-50 dark:hover:bg-red-500/10">Revoke</button>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
        
    </div>
</div>
