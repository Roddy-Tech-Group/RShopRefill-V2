<?php

use App\Domain\Security\Notifications\EmailOtpNotification;
use App\Domain\Wallet\Services\TransactionPinService;
use App\Models\TrustedDevice;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth')] class extends Component {
    // Locked: every public property is client-writable by default, so without
    // this a restricted user could jump straight to the final step from the
    // browser console and skip the checks that gate it.
    #[Locked]
    public string $step = 'password'; // password, otp, pin

    // Server-side proof that the password AND the emailed OTP were both
    // verified in THIS component instance. completeRecovery() refuses to lift
    // the account restriction without it.
    #[Locked]
    public bool $identityVerified = false;

    public string $password = '';

    public string $otp = '';

    public string $pin = '';

    public int $cooldown = 0;
    
    public function mount()
    {
        if (!Auth::user()->isRestricted()) {
            return redirect()->route('dashboard');
        }

        $this->updateCooldown();
    }

    public function updateCooldown()
    {
        $user = Auth::user();
        if (!$user) return;

        $throttleKey = 'recovery_otp_throttle_' . $user->id;
        $expiresAt = Cache::get($throttleKey, 0);
        $this->cooldown = is_numeric($expiresAt) ? (int) max(0, $expiresAt - time()) : 0;
    }
    
    public function verifyPassword()
    {
        $this->validate([
            'password' => ['required', 'string'],
        ]);
        
        if (!Hash::check($this->password, Auth::user()->password)) {
            $this->addError('password', 'Incorrect password.');
            return;
        }
        
        $this->sendOtp();
        $this->step = 'otp';
    }
    
    public function sendOtp()
    {
        $user = Auth::user();
        
        $throttleKey = 'recovery_otp_throttle_' . $user->id;
        $attemptsKey = 'recovery_otp_attempts_' . $user->id;
        
        // Anti-spam with a 3-second grace period for frontend timer drift
        if (Cache::has($throttleKey)) {
            $val = Cache::get($throttleKey);
            if ($val === true || $val === 1 || $val < 1000) {
                Cache::forget($throttleKey);
            } else {
                $remaining = max(0, (int)$val - time());
                if ($remaining <= 3) {
                    Cache::forget($throttleKey);
                } else {
                    $this->addError('otp_resend', 'Please wait before requesting another code.');
                    return;
                }
            }
        }
        
        $attempts = Cache::get($attemptsKey, 0) + 1;
        
        $delays = [
            1 => 60,       // 1st request: 1 min
            2 => 180,      // 2nd request: 3 mins
            3 => 300,      // 3rd request: 5 mins
            4 => 1800,     // 4th request: 30 mins
            5 => 3600,     // 5th request: 1 hour
        ];
        
        if ($attempts > 5) {
            $this->addError('otp_resend', 'Too many attempts. Please contact support.');
            return;
        }
        
        $delay = $delays[$attempts];
        
        // CSPRNG over the full six-digit space (000000 included); a fresh
        // code gets a fresh guess budget.
        $otp = sprintf('%06d', random_int(0, 999999));
        Cache::put('recovery_otp_' . $user->id, $otp, now()->addMinutes(10));
        Cache::forget('recovery_otp_fails_' . $user->id);
        
        Cache::put($throttleKey, now()->addSeconds($delay)->timestamp, now()->addSeconds($delay));
        Cache::put($attemptsKey, $attempts, now()->addHours(24));
        
        $this->cooldown = $delay;
        
        $user->notify(new EmailOtpNotification($otp, 'recovery'));
    }
    
    public function verifyOtp()
    {
        $this->validate(['otp' => 'required|string|size:6']);
        
        $user = Auth::user();
        $storedOtp = Cache::get('recovery_otp_' . $user->id);
        $failsKey = 'recovery_otp_fails_' . $user->id;

        // This code lifts a fraud restriction, so it gets five guesses, not
        // a million: the sixth wrong answer burns the code and the customer
        // must request a fresh one (which the resend backoff paces).
        if (!$storedOtp || !hash_equals($storedOtp, $this->otp)) {
            $fails = (int) Cache::get($failsKey, 0) + 1;
            Cache::put($failsKey, $fails, now()->addMinutes(10));

            if ($fails >= 5) {
                Cache::forget('recovery_otp_' . $user->id);
                Cache::forget($failsKey);
                $this->addError('otp', 'Too many incorrect codes. Request a new code to continue.');
                return;
            }

            $this->addError('otp', 'Invalid or expired code.');
            return;
        }

        Cache::forget('recovery_otp_' . $user->id);
        Cache::forget($failsKey);
        Cache::forget('recovery_otp_throttle_' . $user->id);
        Cache::forget('recovery_otp_attempts_' . $user->id);

        // Password (step 1) and OTP (step 2) are both behind us. This is the
        // only place that may set the flag.
        $this->identityVerified = true;

        if ($user->hasTransactionPin()) {
            $this->step = 'pin';
        } else {
            $this->completeRecovery();
        }
    }
    
    public function verifyPin(TransactionPinService $pinService)
    {
        $this->validate(['pin' => ['required', 'digits:4']]);
        
        try {
            // Re-use the existing service, which handles rate-limiting
            $pinService->verifyPin(Auth::user(), $this->pin);
        } catch (ValidationException $e) {
            $this->addError('pin', $e->validator->errors()->first());
            return;
        }
        
        $this->completeRecovery();
    }
    
    /**
     * Lifts the account restriction and unlocks the transaction PIN.
     *
     * PROTECTED, not public, and deliberately so: Livewire exposes every public
     * method as a client-callable endpoint, so as a public method this could be
     * invoked straight from the browser console ($wire.completeRecovery()) by a
     * restricted user, clearing their own fraud restriction without supplying
     * the password, the emailed OTP or the transaction PIN.
     *
     * The $identityVerified guard is the second layer: it can only be set by
     * verifyOtp(), and it is #[Locked] so the client cannot forge it.
     */
    protected function completeRecovery()
    {
        abort_unless($this->identityVerified, 403);

        $user = Auth::user();

        // 1. Clear restriction
        $user->restricted_at = null;
        
        // 2. Clear pin status if locked (since they verified successfully, we can unlock it)
        $user->pin_status = 'active';
        $user->save();
        
        // 3. Trust the current device with score 70 (if it exists in session)
        $sessionId = session()->getId();
        $userSession = \App\Models\UserSession::where('laravel_session_id', $sessionId)->first();
        
        if ($userSession && $userSession->trusted_device_id) {
            $device = TrustedDevice::find($userSession->trusted_device_id);
            if ($device) {
                // Trust the device conservatively
                $device->trusted_since = now();
                $device->trust_score = 70;
                $device->save();
            }
        }
        
        // 4. Session rotation (logout other devices requires the unhashed password)
        // Since we collected it in step 1, we can pass it here.
        Auth::logoutOtherDevices($this->password);
        session()->regenerate();
        
        // Send email notification for Account Recovered
        $user->notify(new \App\Domain\Security\Notifications\AccountRecoveredNotification(request()->ip()));
        return redirect()->route('dashboard')->with('status', 'Account successfully recovered.');
    }
}; ?>

<div class="mx-auto max-w-lg pt-10">
    <div class="rounded-[16px] bg-white p-8 shadow-xl shadow-zinc-900/5 ring-1 ring-zinc-200 dark:bg-[#0c1a36] dark:ring-white/10">
        
        <div class="mb-8 text-center">
            <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-blue-100 text-blue-600 dark:bg-blue-500/20 dark:text-blue-400">
                <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                </svg>
            </div>
            <h1 class="mt-4 text-2xl font-bold text-zinc-900 dark:text-white">Recover your account</h1>
            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">Complete these security steps to unlock your financial operations.</p>
        </div>

        {{-- Step 1: Password --}}
        <div x-show="$wire.step === 'password'" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 translate-x-4" x-transition:enter-end="opacity-100 translate-x-0">
            <form wire:submit="verifyPassword" class="space-y-5">
                <div x-data="{ show: false }">
                    <label for="password" class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Account Password</label>
                    <div class="relative">
                        <input
                            wire:model="password"
                            id="password"
                            :type="show ? 'text' : 'password'"
                            required
                            placeholder="Enter your password"
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
                    @error('password') 
                        <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 5000)" 
                             x-transition:leave="transition ease-in duration-300" 
                             x-transition:leave-start="opacity-100 transform translate-y-0" 
                             x-transition:leave-end="opacity-0 transform -translate-y-2"
                             class="mt-1 text-xs text-red-600">
                            {{ $message }}
                        </div>
                    @enderror
                </div>
                <button type="submit" wire:loading.attr="disabled" wire:target="verifyPassword" class="flex w-full items-center justify-center gap-2 rounded-[12px] bg-blue-600 px-5 py-3 text-sm font-semibold text-white shadow-sm shadow-blue-600/25 transition-colors hover:bg-blue-700 disabled:opacity-75 disabled:cursor-not-allowed">
                    <svg wire:loading wire:target="verifyPassword" class="h-4 w-4 animate-spin text-white" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" stroke-opacity="0.25"></circle>
                        <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="4" stroke-linecap="round"></path>
                    </svg>
                    <span wire:loading.remove wire:target="verifyPassword">Continue</span>
                    <span wire:loading wire:target="verifyPassword">Verifying...</span>
                </button>
            </form>
        </div>

        {{-- Step 2: OTP --}}
        <div x-show="$wire.step === 'otp'" x-cloak style="display:none;" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 translate-x-4" x-transition:enter-end="opacity-100 translate-x-0">
            <form wire:submit="verifyOtp" class="space-y-5">
                <div>
                    <label for="otp" class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Email Verification Code</label>
                    <p class="mb-3 text-xs text-zinc-500">We've sent a 6-digit code to {{ auth()->user()->email }}</p>
                    <input
                        wire:model="otp"
                        id="otp"
                        type="text"
                        inputmode="numeric"
                        maxlength="6"
                        required
                        placeholder="000000"
                        class="w-full rounded-[12px] border border-zinc-300 bg-white px-3.5 py-2.5 text-center text-xl tracking-[0.5em] text-black placeholder:tracking-normal outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700 dark:bg-[#1d3252] dark:text-white"
                    />
                    @error('otp') 
                        <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 5000)" 
                             x-transition:leave="transition ease-in duration-300" 
                             x-transition:leave-start="opacity-100 transform translate-y-0" 
                             x-transition:leave-end="opacity-0 transform -translate-y-2"
                             class="mt-1 text-center text-xs text-red-600">
                            {{ $message }}
                        </div>
                    @enderror
                </div>
                
                <div class="flex items-center justify-center" x-data="{ 
                    cooldown: @entangle('cooldown'),
                    init() {
                        setInterval(() => {
                            if (this.cooldown > 0) this.cooldown--;
                        }, 1000);
                    },
                    get cooldownText() {
                        if (this.cooldown <= 0) return '';
                        if (this.cooldown < 60) return `in ${this.cooldown}s`;
                        let m = Math.floor(this.cooldown / 60);
                        let s = this.cooldown % 60;
                        return `in ${m}m ${s > 0 ? s + 's' : ''}`.trim();
                    }
                }">
                    <button type="button" wire:click="sendOtp" :disabled="cooldown > 0" wire:loading.attr="disabled" wire:target="sendOtp" :class="cooldown > 0 ? 'opacity-50 cursor-not-allowed' : 'text-blue-600 hover:text-blue-700 dark:text-blue-400 disabled:opacity-50'" class="text-xs font-semibold">
                        <span wire:loading.remove wire:target="sendOtp" x-show="cooldown <= 0">Resend code</span>
                        <span wire:loading.remove wire:target="sendOtp" x-show="cooldown > 0" x-text="'Resend ' + cooldownText" x-cloak></span>
                        <span wire:loading wire:target="sendOtp">Sending...</span>
                    </button>
                </div>
                @error('otp_resend') 
                    <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 5000)" 
                         x-transition:leave="transition ease-in duration-300" 
                         x-transition:leave-start="opacity-100 transform translate-y-0" 
                         x-transition:leave-end="opacity-0 transform -translate-y-2"
                         class="mt-1 text-center text-xs text-red-600">
                        {{ $message }}
                    </div>
                @enderror

                <button type="submit" wire:loading.attr="disabled" wire:target="verifyOtp" class="flex w-full items-center justify-center gap-2 rounded-[12px] bg-blue-600 px-5 py-3 text-sm font-semibold text-white shadow-sm shadow-blue-600/25 transition-colors hover:bg-blue-700 disabled:opacity-75 disabled:cursor-not-allowed">
                    <svg wire:loading wire:target="verifyOtp" class="h-4 w-4 animate-spin text-white" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" stroke-opacity="0.25"></circle>
                        <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="4" stroke-linecap="round"></path>
                    </svg>
                    <span wire:loading.remove wire:target="verifyOtp">Verify Code</span>
                    <span wire:loading wire:target="verifyOtp">Verifying...</span>
                </button>
            </form>
        </div>

        {{-- Step 3: PIN --}}
        <div x-show="$wire.step === 'pin'" x-cloak style="display:none;" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 translate-x-4" x-transition:enter-end="opacity-100 translate-x-0">
            <form wire:submit="verifyPin" class="space-y-5">
                <div x-data="{ show: false }">
                    <label for="pin" class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Transaction PIN</label>
                    <p class="mb-3 text-xs text-zinc-500">Enter your 4-digit Transaction PIN to complete the process.</p>
                    <div class="relative">
                        <input
                            wire:model="pin"
                            id="pin"
                            :type="show ? 'text' : 'password'"
                            inputmode="numeric"
                            maxlength="4"
                            required
                            placeholder="4 digits"
                            class="w-full rounded-[12px] border border-zinc-300 bg-white px-3.5 py-2.5 pr-11 text-center text-xl tracking-[0.5em] text-black placeholder:tracking-normal outline-none transition-colors focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700 dark:bg-[#1d3252] dark:text-white"
                        />
                        <button type="button" @click="show = !show" class="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-600" :aria-label="show ? 'Hide PIN' : 'Show PIN'">
                            <svg x-show="!show" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            </svg>
                            <svg x-show="show" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true" style="display:none;">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.244 7.244L19.5 19.5m-2.876-2.876L13.875 13.875M9.878 9.878a3 3 0 105.249 5.249"/>
                            </svg>
                        </button>
                    </div>
                    @error('pin') 
                        <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 5000)" 
                             x-transition:leave="transition ease-in duration-300" 
                             x-transition:leave-start="opacity-100 transform translate-y-0" 
                             x-transition:leave-end="opacity-0 transform -translate-y-2"
                             class="mt-1 text-center text-xs text-red-600">
                            {{ $message }}
                        </div>
                    @enderror
                </div>
                
                <button type="submit" wire:loading.attr="disabled" wire:target="verifyPin" class="flex w-full items-center justify-center gap-2 rounded-[12px] bg-blue-600 px-5 py-3 text-sm font-semibold text-white shadow-sm shadow-blue-600/25 transition-colors hover:bg-blue-700 disabled:opacity-75 disabled:cursor-not-allowed">
                    <svg wire:loading wire:target="verifyPin" class="h-4 w-4 animate-spin text-white" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" stroke-opacity="0.25"></circle>
                        <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="4" stroke-linecap="round"></path>
                    </svg>
                    <span wire:loading.remove wire:target="verifyPin">Recover Account</span>
                    <span wire:loading wire:target="verifyPin">Recovering...</span>
                </button>
            </form>
        </div>

    </div>
</div>
