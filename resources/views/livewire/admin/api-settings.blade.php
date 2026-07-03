{{-- FILE: resources/views/livewire/admin/api-settings.blade.php --}}
<?php

use App\Models\SiteSetting;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.admin')]
#[Title('API & Integrations')]
class extends Component {
    /** Per-key save timestamps so each row can flash its own confirmation. */
    public array $savedKeys = [];

    /**
     * Pretty labels for each provider derived from the second segment of the
     * key name. Anything not listed falls back to a Title-Cased segment.
     *
     * @var array<string, array{label: string, blurb: string}>
     */
    private array $providers = [
        'flutterwave'   => ['label' => 'Flutterwave',          'blurb' => 'Card, mobile money, bank transfer and USSD payment processing.'],
        'nowpayments'   => ['label' => 'NowPayments',          'blurb' => 'Cryptocurrency payment processing.'],
        'zendit'        => ['label' => 'Zendit',               'blurb' => 'Gift card + bill-payment supplier.'],
        'airalo'        => ['label' => 'Airalo',               'blurb' => 'Travel + local eSIM supplier (partner API).'],
        'google_oauth'  => ['label' => 'Google OAuth',         'blurb' => 'Sign-in with Google for customers.'],
        'google_places' => ['label' => 'Google Places',        'blurb' => 'Address autocomplete on checkout + KYC.'],
        'turnstile'     => ['label' => 'Cloudflare Turnstile', 'blurb' => 'Bot-protection challenge on auth flows.'],
        'resend'        => ['label' => 'Resend',               'blurb' => 'Transactional email + newsletter audience sync.'],
        'whatsapp'      => ['label' => 'WhatsApp Business',    'blurb' => 'Meta Cloud API for support chat.'],
        'trustpilot'    => ['label' => 'Trustpilot',           'blurb' => 'Public review badge + business profile sync.'],
        'sentry'        => ['label' => 'Sentry',               'blurb' => 'Error monitoring + release tracking.'],
    ];

    #[Computed]
    public function providerCards()
    {
        return SiteSetting::query()
            ->where('group', 'integrations')
            ->orderBy('key')
            ->get()
            ->groupBy(fn ($s) => explode('.', $s->key)[1] ?? 'other')
            ->map(function ($settings, $providerKey) {
                $meta = $this->providers[$providerKey] ?? ['label' => ucwords(str_replace('_', ' ', $providerKey)), 'blurb' => ''];

                return [
                    'key'      => $providerKey,
                    'label'    => $meta['label'],
                    'blurb'    => $meta['blurb'],
                    'settings' => $settings->sortBy('key')->values(),
                ];
            })
            ->sortBy('label')
            ->values();
    }

    public function updateSetting(string $key, string $value): void
    {
        // Preserve the existing group + description so SiteSetting::put()'s
        // "group = general" default doesn't quietly move the integration key
        // out of the `integrations` group on every save.
        $existing = SiteSetting::query()->where('key', $key)->first();

        SiteSetting::put(
            $key,
            $value,
            $existing?->group ?? 'integrations',
            $existing?->description,
        );

        $this->savedKeys[$key] = now()->toTimeString();
        unset($this->providerCards);
    }

    public function displayValue(mixed $value): string
    {
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return (string) ($value ?? '');
    }

    /**
     * The portion of the key after `integration.<provider>.` so the input
     * label reads as the short variable name ("public_key") instead of the
     * full SiteSetting key.
     */
    public function shortName(string $key): string
    {
        $parts = explode('.', $key);

        return $parts[2] ?? $parts[count($parts) - 1];
    }
}; ?>

<div>
    <x-slot:heading>API & Integrations</x-slot:heading>
    <x-slot:subheading>Partner API credentials and integration keys. Values you store here mirror the .env entries; pair with a config bridge before they take effect in code.</x-slot:subheading>

    {{-- Security warning so the admin understands the implications. --}}
    <div class="mb-6 flex items-start gap-3 rounded-[12px] border-[1.5px] border-amber-200 bg-amber-50 p-4 dark:border-amber-500/40 dark:bg-amber-500/10">
        <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-600 dark:text-amber-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
        </svg>
        <div class="min-w-0 text-sm text-amber-900 dark:text-amber-100">
            <p class="font-semibold">Treat these values like passwords.</p>
            <p class="mt-1 text-xs leading-relaxed">Values are stored in the database, not encrypted at rest by default. Restrict admin access tightly and rotate any key you suspect has been exposed.</p>
        </div>
    </div>

    <div class="flex flex-col gap-6">

        @forelse ($this->providerCards as $card)
            <div class="overflow-hidden rounded-[12px] border-[1.5px] border-white bg-white shadow-sm shadow-zinc-900/[0.04] dark:border-white dark:bg-[#1d3252]">

                {{-- Provider header pill --}}
                <div class="mx-3 my-3 rounded-[12px] bg-blue-50 px-6 py-3 ring-2 ring-blue-500 dark:bg-blue-600/15 dark:ring-blue-400">
                    <h2 class="text-sm font-bold text-blue-700 dark:text-blue-300">{{ $card['label'] }}</h2>
                    @if ($card['blurb'])
                        <p class="mt-0.5 text-[11px] text-blue-700/70 dark:text-blue-300/70">{{ $card['blurb'] }}</p>
                    @endif
                </div>

                <ul class="divide-inset">
                    @foreach ($card['settings'] as $setting)
                        @php $displayVal = $this->displayValue($setting->value); @endphp
                        <li class="group relative mx-3 flex flex-col gap-3 px-5 py-4 transition-all hover:bg-blue-50 hover:rounded-[12px] sm:flex-row sm:items-center sm:gap-6 dark:hover:bg-blue-600/15 dark:hover:ring-blue-400">
                            {{-- Key + description --}}
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <code class="rounded-[5px] bg-zinc-100 px-1.5 py-0.5 text-[12px] font-semibold text-zinc-800 dark:bg-zinc-700/50 dark:text-zinc-200">{{ $this->shortName($setting->key) }}</code>
                                    @if (isset($this->savedKeys[$setting->key]))
                                        <span class="text-[10px] font-semibold text-emerald-600 dark:text-emerald-400">Saved</span>
                                    @endif
                                </div>
                                @if ($setting->description)
                                    <p class="mt-1 text-xs leading-relaxed text-zinc-500 dark:text-zinc-400">{{ $setting->description }}</p>
                                @endif
                                <p class="mt-1 text-[10px] font-mono text-zinc-400 dark:text-zinc-500">{{ $setting->key }}</p>
                            </div>

                            {{-- Secret input with reveal toggle + Save button. --}}
                            <div
                                class="flex w-full items-center gap-2 sm:w-auto"
                                x-data="{ initial: @js($displayVal), value: @js($displayVal), reveal: false }"
                            >
                                <div class="relative w-full sm:w-80 lg:w-96">
                                    <input
                                        :type="reveal ? 'text' : 'password'"
                                        x-model="value"
                                        @keydown.enter.prevent="value !== initial && ($wire.updateSetting('{{ $setting->key }}', value).then(() => initial = value))"
                                        autocomplete="off"
                                        spellcheck="false"
                                        placeholder="Not set"
                                        class="w-full rounded-[12px] border border-zinc-200 bg-white py-2.5 pl-3 pr-10 text-sm font-mono outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 dark:border-zinc-700/60 dark:bg-[#0c1a36] dark:text-white"
                                        aria-label="{{ $setting->key }}"
                                    >
                                    <button
                                        type="button"
                                        @click="reveal = !reveal"
                                        :aria-label="reveal ? 'Hide secret' : 'Reveal secret'"
                                        class="absolute right-2 top-1/2 -translate-y-1/2 flex h-7 w-7 items-center justify-center rounded-[6px] text-zinc-500 transition-colors hover:bg-zinc-100 hover:text-zinc-900 dark:hover:bg-zinc-700/50 dark:hover:text-white"
                                    >
                                        <svg x-show="!reveal" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                        <svg x-show="reveal" x-cloak class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.244 7.244L19.5 19.5m-2.876-2.876L13.875 13.875M9.878 9.878a3 3 0 105.249 5.249"/></svg>
                                    </button>
                                </div>

                                <button
                                    type="button"
                                    :disabled="value === initial"
                                    @click="$wire.updateSetting('{{ $setting->key }}', value).then(() => initial = value)"
                                    class="inline-flex h-10 shrink-0 items-center gap-1.5 rounded-[12px] bg-blue-600 px-3.5 text-xs font-bold uppercase tracking-wide text-white transition-colors hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-zinc-300 disabled:text-zinc-500 dark:disabled:bg-zinc-700 dark:disabled:text-zinc-500"
                                >
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                    </svg>
                                    Save
                                </button>
                            </div>
                        </li>
                    @endforeach
                </ul>

            </div>
        @empty
            <div class="rounded-[12px] border-[1.5px] border-white bg-white p-12 text-center shadow-sm shadow-zinc-900/[0.04] dark:border-white dark:bg-[#1d3252]">
                <p class="text-sm font-semibold text-zinc-900 dark:text-white">No integration keys seeded yet</p>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Run <code class="rounded-[5px] bg-zinc-100 px-1.5 py-0.5 dark:bg-zinc-700/50">php artisan migrate</code> to seed the inventory of supported partners.</p>
            </div>
        @endforelse

    </div>
</div>
