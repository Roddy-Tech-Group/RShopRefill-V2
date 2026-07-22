<div>
    <div class="mb-6 flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Communication Analytics</h1>
            <p class="text-sm text-gray-500">Monitor all outgoing messages and delivery rates.</p>
        </div>
        <div>
            <select wire:model.live="dateRange" class="bg-white border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-indigo-500 focus:border-indigo-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white">
                <option value="1">Last 24 Hours</option>
                <option value="7">Last 7 Days</option>
                <option value="30">Last 30 Days</option>
            </select>
        </div>
    </div>

    <!-- Stats Grid -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
        <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-sm border border-gray-100 dark:border-gray-700">
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Sent</p>
            <p class="text-3xl font-bold text-gray-900 dark:text-white mt-2">{{ number_format($stats['total_sent']) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-sm border border-gray-100 dark:border-gray-700">
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Failed Deliveries</p>
            <p class="text-3xl font-bold text-red-600 mt-2">{{ number_format($stats['failed']) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-sm border border-gray-100 dark:border-gray-700">
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Emails Sent</p>
            <p class="text-3xl font-bold text-gray-900 dark:text-white mt-2">{{ number_format($stats['email_count']) }}</p>
        </div>
        <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow-sm border border-gray-100 dark:border-gray-700">
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Push Sent</p>
            <p class="text-3xl font-bold text-gray-900 dark:text-white mt-2">{{ number_format($stats['push_count']) }}</p>
        </div>
    </div>

    <!-- Data Table -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-100 dark:border-gray-700">
        <div class="p-4 border-b border-gray-100 dark:border-gray-700 flex justify-between items-center">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Communication Logs</h3>
            <div class="w-64">
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search by email, name or title..." class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-indigo-500 focus:border-indigo-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white">
            </div>
        </div>
        
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left text-gray-500 dark:text-gray-400">
                <thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-3">Timestamp</th>
                        <th class="px-4 py-3">Recipient</th>
                        <th class="px-4 py-3">Channel</th>
                        <th class="px-4 py-3">Category</th>
                        <th class="px-4 py-3">Title</th>
                        <th class="px-4 py-3">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                        <tr class="border-b dark:border-gray-700">
                            <td class="px-4 py-3 whitespace-nowrap">{{ $log->created_at->format('M d, H:i:s') }}</td>
                            <td class="px-4 py-3">
                                @if($log->user)
                                    <div class="font-medium text-gray-900 dark:text-white">{{ $log->user->name }}</div>
                                    <div class="text-xs">{{ $log->user->email }}</div>
                                @else
                                    <span class="text-gray-400">System / Unknown</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 uppercase">{{ $log->channel }}</td>
                            <td class="px-4 py-3">{{ Str::headline($log->category) }}</td>
                            <td class="px-4 py-3 font-medium text-gray-900 dark:text-white">{{ Str::limit($log->title, 40) }}</td>
                            <td class="px-4 py-3">
                                @if($log->status === 'sent')
                                    <span class="bg-green-100 text-green-800 text-xs font-medium px-2.5 py-0.5 rounded dark:bg-green-900 dark:text-green-300">Sent</span>
                                @elseif($log->status === 'failed')
                                    <span class="bg-red-100 text-red-800 text-xs font-medium px-2.5 py-0.5 rounded dark:bg-red-900 dark:text-red-300" title="{{ $log->error_message }}">Failed</span>
                                @else
                                    <span class="bg-gray-100 text-gray-800 text-xs font-medium px-2.5 py-0.5 rounded dark:bg-gray-700 dark:text-gray-300">{{ ucfirst($log->status) }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-gray-500">No communication logs found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4 border-t border-gray-100 dark:border-gray-700">
            {{ $logs->links() }}
        </div>
    </div>
</div>
