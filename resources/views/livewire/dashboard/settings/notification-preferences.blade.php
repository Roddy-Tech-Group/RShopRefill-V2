{{-- Per-topic email and push switches, keyed by preference group. The groups
     come from the component rather than from the category enum, because several
     categories share one stored column pair and a switch per category would let
     a customer think they had turned off one of a pair. --}}
<div>
    <div class="mb-6">
        <h2 class="text-xl font-bold text-zinc-900 dark:text-white">Notification preferences</h2>
        <p class="text-sm text-zinc-500 dark:text-zinc-400">Choose how you hear about activity on your account. Security and verification messages always send.</p>
    </div>

    <div class="space-y-4">
        @foreach ($this->groups() as $group => $label)
            <div class="rounded-[12px] bg-white p-5 shadow-sm dark:bg-zinc-800">
                <h3 class="font-semibold text-zinc-900 dark:text-white">{{ $label }}</h3>

                <div class="mt-4 space-y-3">
                    @foreach (['email' => 'Email', 'push' => 'Push notifications'] as $channel => $channelLabel)
                        @php $isOn = $preferences[$group][$channel]; @endphp
                        <label class="flex cursor-pointer items-center">
                            <div class="relative">
                                <input
                                    type="checkbox"
                                    class="sr-only"
                                    wire:click="togglePreference('{{ $group }}', '{{ $channel }}')"
                                    @checked($isOn)
                                >
                                <div class="block h-6 w-10 rounded-full transition {{ $isOn ? 'bg-blue-600' : 'bg-zinc-200 dark:bg-zinc-600' }}"></div>
                                <div class="absolute left-1 top-1 h-4 w-4 rounded-full bg-white transition-transform {{ $isOn ? 'translate-x-4' : '' }}"></div>
                            </div>
                            <span class="ml-3 text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ $channelLabel }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</div>
