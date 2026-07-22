<div>
    <div class="mb-6">
        <h2 class="text-xl font-bold text-gray-900 dark:text-white">Notification Preferences</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400">Choose how you want to be notified about activity on your account.</p>
    </div>

    <div class="space-y-6">
        @foreach(\App\Domain\Communication\Enums\CommunicationCategory::cases() as $category)
            @if($category->isUserDisableable())
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-100 dark:border-gray-700 p-5">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <h3 class="font-semibold text-gray-900 dark:text-white">{{ Str::headline($category->value) }}</h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Receive updates about {{ strtolower(Str::headline($category->value)) }}.</p>
                        </div>
                    </div>
                    
                    <div class="space-y-3">
                        <label class="flex items-center cursor-pointer">
                            <div class="relative">
                                <input type="checkbox" class="sr-only" 
                                    wire:click="togglePreference('{{ $category->value }}', 'email')" 
                                    {{ $preferences[$category->value]['email'] ? 'checked' : '' }}>
                                <div class="block w-10 h-6 bg-gray-200 dark:bg-gray-600 rounded-full {{ $preferences[$category->value]['email'] ? 'bg-indigo-500 dark:bg-indigo-600' : '' }} transition"></div>
                                <div class="dot absolute left-1 top-1 bg-white w-4 h-4 rounded-full transition transform {{ $preferences[$category->value]['email'] ? 'translate-x-4' : '' }}"></div>
                            </div>
                            <div class="ml-3 text-sm font-medium text-gray-700 dark:text-gray-300">
                                Email
                            </div>
                        </label>
                        
                        <label class="flex items-center cursor-pointer">
                            <div class="relative">
                                <input type="checkbox" class="sr-only" 
                                    wire:click="togglePreference('{{ $category->value }}', 'push')" 
                                    {{ $preferences[$category->value]['push'] ? 'checked' : '' }}>
                                <div class="block w-10 h-6 bg-gray-200 dark:bg-gray-600 rounded-full {{ $preferences[$category->value]['push'] ? 'bg-indigo-500 dark:bg-indigo-600' : '' }} transition"></div>
                                <div class="dot absolute left-1 top-1 bg-white w-4 h-4 rounded-full transition transform {{ $preferences[$category->value]['push'] ? 'translate-x-4' : '' }}"></div>
                            </div>
                            <div class="ml-3 text-sm font-medium text-gray-700 dark:text-gray-300">
                                Push Notifications
                            </div>
                        </label>
                    </div>
                </div>
            @endif
        @endforeach
    </div>
</div>
