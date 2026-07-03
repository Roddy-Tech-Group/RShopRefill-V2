{{-- FILE: resources/views/livewire/admin/system-settings.blade.php --}}
<?php

use App\Models\SiteSetting;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.admin')]
#[Title('System Settings')]
class extends Component {
    /**
     * Flash message for save feedback.
     * Keyed by setting key so each row can show its own confirmation.
     *
     * @var array<string, string>
     */
    public array $savedKeys = [];

    /**
     * Display order for the groups - operational toggles (system + features)
     * sit at the top so the admin lands on the kill-switches first. Anything
     * not in this list falls to the bottom alphabetically.
     *
     * @var array<int, string>
     */
    private array $groupOrder = ['system', 'features', 'site', 'email', 'contact', 'social', 'seo', 'trust'];

    /**
     * Within-group key priority. Lower number = higher position. Anything not
     * listed falls to 99 and sorts alphabetically among itself. Lets the most
     * operational keys (maintenance toggle + message) sit at the very top of
     * the system card without renaming columns or adding a sort_order field.
     *
     * @var array<string, int>
     */
    private array $keyOrder = [
        'system.maintenance_mode'    => 1,
        'system.maintenance_message' => 2,
        'system.version'             => 3,
        'system.footer_copyright'    => 4,
    ];

    #[Computed]
    public function settings()
    {
        $groupOrder = array_flip($this->groupOrder);
        $keyOrder   = $this->keyOrder;

        // Integration / API keys live on the dedicated /admin/api-settings
        // page; exclude them here so they don't clutter system settings.
        return SiteSetting::query()->where('group', '!=', 'integrations')->get()
            ->sort(function ($a, $b) use ($keyOrder) {
                $aPos = $keyOrder[$a->key] ?? 99;
                $bPos = $keyOrder[$b->key] ?? 99;

                return $aPos === $bPos ? strcmp($a->key, $b->key) : $aPos <=> $bPos;
            })
            ->values()
            ->groupBy('group')
            ->sortBy(fn ($_, $group) => $groupOrder[$group] ?? 999);
    }

    /**
     * Persist a setting value. The value is stored as JSON in the database
     * (the SiteSetting model casts the `value` column as array), so we wrap
     * the raw string the admin typed in an associative wrapper for scalar
     * values, or decode it if it looks like valid JSON already.
     *
     * CRITICAL: we look the existing row up first and pass its group + description
     * back through put(), otherwise SiteSetting::put()'s "group = general"
     * default silently re-groups the row on every save and the toggle vanishes
     * from its proper card.
     */
    public function updateSetting(string $key, string $value): void
    {
        $existing = SiteSetting::query()->where('key', $key)->first();

        $decoded = json_decode($value, true);
        $stored  = json_last_error() === JSON_ERROR_NONE ? $decoded : $value;

        SiteSetting::put(
            $key,
            $stored,
            $existing?->group ?? 'general',
            $existing?->description,
        );

        $this->savedKeys[$key] = now()->toTimeString();
        unset($this->settings);
    }

    /**
     * Return the scalar representation of a setting value for display
     * inside an <input>. Arrays and objects are re-encoded as JSON strings.
     */
    public function displayValue(mixed $value): string
    {
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return (string) ($value ?? '');
    }
}; ?>

<div>
    <x-slot:heading>System Settings</x-slot:heading>
    <x-slot:subheading>Site-wide configuration stored in the database. Changes take effect immediately; cached values refresh within 1 hour.</x-slot:subheading>

    <div class="flex flex-col gap-6">

        @forelse ($this->settings as $group => $groupSettings)
            <div class="overflow-hidden rounded-[12px] border-[1.5px] border-white bg-white shadow-sm shadow-zinc-900/[0.04] dark:border-white dark:bg-[#1d3252]">
                {{-- Group header pill --}}
                <div class="mx-3 my-3 rounded-[12px] bg-blue-50 px-6 py-3 ring-2 ring-blue-500 dark:bg-blue-600/15 dark:ring-blue-400">
                    <h2 class="text-[11px] font-bold uppercase tracking-wider text-blue-700 dark:text-blue-300">{{ $group }}</h2>
                </div>

                <ul class="divide-inset">
                    @foreach ($groupSettings as $setting)
                        @php $displayVal = $this->displayValue($setting->value); @endphp
                        <li class="group relative mx-3 flex flex-col gap-3 rounded-[12px] px-5 py-4 transition-colors hover:bg-blue-50 sm:flex-row sm:items-center sm:gap-6 dark:hover:bg-blue-600/15">
                            {{-- Key + description --}}
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <code class="rounded-[5px] bg-zinc-100 px-1.5 py-0.5 text-[11px] font-semibold text-zinc-700 dark:bg-zinc-700/50 dark:text-zinc-300">{{ $setting->key }}</code>
                                    @if (isset($this->savedKeys[$setting->key]))
                                        <span class="text-[10px] font-semibold text-emerald-600 dark:text-emerald-400">Saved</span>
                                    @endif
                                </div>
                                @if ($setting->description)
                                    <p class="mt-1 text-xs leading-relaxed text-zinc-500 dark:text-zinc-400">{{ $setting->description }}</p>
                                @endif
                            </div>

                            {{-- Editable value input + explicit Save button.
                                 Boolean-style settings (value is on/off or
                                 true/false) render a toggle switch instead of
                                 a text input - admin flips it, change saves
                                 immediately, no Save button needed. Everything
                                 else uses the text input + Save button.
                                 Alpine tracks the initial value so the Save
                                 button only enables when the field is dirty. --}}
                            @php $isToggle = in_array(strtolower((string) $displayVal), ['on', 'off', 'true', 'false', '1', '0'], true); @endphp

                            @if ($isToggle)
                                <div
                                    class="flex w-full items-center gap-3 sm:w-auto"
                                    x-data="{
                                        value: @js($displayVal),
                                        get isOn() { return ['on', 'true', '1'].includes(String(this.value).toLowerCase()); },
                                        toggle() {
                                            const next = this.isOn ? 'off' : 'on';
                                            this.value = next;
                                            $wire.updateSetting('{{ $setting->key }}', next);
                                        },
                                    }"
                                >
                                    <button
                                        type="button"
                                        role="switch"
                                        :aria-checked="isOn.toString()"
                                        @click="toggle()"
                                        :class="isOn ? 'bg-emerald-500' : 'bg-zinc-300 dark:bg-zinc-700'"
                                        class="relative inline-flex h-7 w-12 shrink-0 cursor-pointer rounded-full transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/50"
                                        aria-label="{{ $setting->key }}"
                                    >
                                        <span
                                            :class="isOn ? 'translate-x-5' : 'translate-x-0.5'"
                                            class="pointer-events-none inline-block h-6 w-6 translate-y-0.5 transform rounded-full bg-white shadow-sm transition-transform"
                                        ></span>
                                    </button>
                                    <span
                                        :class="isOn ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-500 dark:text-zinc-400'"
                                        class="text-xs font-bold uppercase tracking-wider"
                                        x-text="isOn ? 'On' : 'Off'"
                                    >Off</span>
                                </div>
                            @else
                                <div
                                    class="flex w-full items-center gap-2 sm:w-auto"
                                    x-data="{ initial: @js($displayVal), value: @js($displayVal) }"
                                >
                                    <input
                                        type="text"
                                        x-model="value"
                                        @keydown.enter.prevent="value !== initial && ($wire.updateSetting('{{ $setting->key }}', value).then(() => initial = value))"
                                        class="w-full rounded-[12px] border border-zinc-200 bg-white py-2.5 px-3 text-sm font-mono outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15 sm:w-72 lg:w-96 dark:border-zinc-700/60 dark:bg-[#0c1a36] dark:text-white"
                                        placeholder="null"
                                        aria-label="{{ $setting->key }}"
                                    />
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
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @empty
            <div class="rounded-[12px] border-[1.5px] border-white bg-white p-12 text-center shadow-sm shadow-zinc-900/[0.04] dark:border-white dark:bg-[#1d3252]">
                <p class="text-sm font-semibold text-zinc-900 dark:text-white">No settings configured</p>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    Use <code class="rounded-[5px] bg-zinc-100 px-1.5 py-0.5 dark:bg-zinc-700/50">SiteSetting::put('key', $value, 'group')</code> to seed settings, or run the site settings seeder.
                </p>
            </div>
        @endforelse

        {{-- Cache note --}}
        <p class="text-[11px] text-zinc-500 dark:text-zinc-400">
            Settings are cached for 1 hour per key via <code class="rounded-[5px] bg-zinc-100 px-1 dark:bg-zinc-700/50">SiteSetting::get()</code>. Runtime consumers pick up changes at next cache expiry. To force immediate propagation, run <code class="rounded-[5px] bg-zinc-100 px-1 dark:bg-zinc-700/50">php artisan cache:clear</code>.
        </p>
    </div>
</div>
