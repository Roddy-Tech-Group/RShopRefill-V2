<div>
    <div class="flex flex-1 flex-col gap-4 sm:gap-6">
        {{-- KPI grid --}}
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4 md:grid-cols-4">
            
            <div class="relative flex flex-col overflow-hidden rounded-[12px] bg-white p-4 shadow-sm shadow-zinc-900/5 sm:p-5">
                <div class="relative z-10 flex flex-1 flex-col">
                    <div class="flex items-start justify-between gap-2">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[12px] bg-blue-100 text-blue-600 sm:h-11 sm:w-11">
                            <svg class="h-5 w-5 sm:h-6 sm:w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9.5a2.5 2.5 0 00-2.5-2.5H15M9 11l3 3L22 4" />
                            </svg>
                        </span>
                    </div>
                    <p class="mt-3 text-xs font-medium text-zinc-600 sm:text-sm">Pending Tickets</p>
                    <p class="mt-0.5 text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">{{ number_format($pendingTickets) }}</p>
                    <p class="mt-auto pt-3 text-[11px] text-zinc-600 sm:text-xs">Needs attention</p>
                </div>
            </div>

            <div class="relative flex flex-col overflow-hidden rounded-[12px] bg-white p-4 shadow-sm shadow-zinc-900/5 sm:p-5">
                <div class="relative z-10 flex flex-1 flex-col">
                    <div class="flex items-start justify-between gap-2">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[12px] bg-purple-100 text-purple-600 sm:h-11 sm:w-11">
                            <svg class="h-5 w-5 sm:h-6 sm:w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9.5a2.5 2.5 0 00-2.5-2.5H14" />
                            </svg>
                        </span>
                    </div>
                    <p class="mt-3 text-xs font-medium text-zinc-600 sm:text-sm">Total Blog Posts</p>
                    <p class="mt-0.5 text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">{{ number_format($totalBlogPosts) }}</p>
                    <p class="mt-auto pt-3 text-[11px] text-zinc-600 sm:text-xs">Published articles</p>
                </div>
            </div>

            <div class="relative flex flex-col overflow-hidden rounded-[12px] bg-white p-4 shadow-sm shadow-zinc-900/5 sm:p-5">
                <div class="relative z-10 flex flex-1 flex-col">
                    <div class="flex items-start justify-between gap-2">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[12px] bg-orange-100 text-orange-600 sm:h-11 sm:w-11">
                            <svg class="h-5 w-5 sm:h-6 sm:w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z" />
                            </svg>
                        </span>
                    </div>
                    <p class="mt-3 text-xs font-medium text-zinc-600 sm:text-sm">Active Campaigns</p>
                    <p class="mt-0.5 text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">{{ number_format($activeCampaigns) }}</p>
                    <p class="mt-auto pt-3 text-[11px] text-zinc-600 sm:text-xs">Running newsletters</p>
                </div>
            </div>

            <div class="relative flex flex-col overflow-hidden rounded-[12px] bg-white p-4 shadow-sm shadow-zinc-900/5 sm:p-5">
                <div class="relative z-10 flex flex-1 flex-col">
                    <div class="flex items-start justify-between gap-2">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[12px] bg-emerald-100 text-emerald-600 sm:h-11 sm:w-11">
                            <svg class="h-5 w-5 sm:h-6 sm:w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                            </svg>
                        </span>
                    </div>
                    <p class="mt-3 text-xs font-medium text-zinc-600 sm:text-sm">New Users Today</p>
                    <p class="mt-0.5 text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl">{{ number_format($newUsersToday) }}</p>
                    <p class="mt-auto pt-3 text-[11px] text-zinc-600 sm:text-xs">Registered today</p>
                </div>
            </div>

        </div>

        {{-- Tables row --}}
        <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">

            {{-- Latest Tickets --}}
            <div class="min-w-0 overflow-hidden rounded-[12px] bg-white shadow-sm shadow-zinc-900/5">
                <div class="flex items-center justify-between border-b border-zinc-200 p-5">
                    <div class="flex items-center gap-2">
                        <svg class="h-5 w-5 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                        </svg>
                        <h2 class="text-base font-semibold text-zinc-900">Latest Support Tickets</h2>
                    </div>
                    <a href="{{ route('admin.support-tickets') }}" class="rounded-[12px] border border-zinc-200 bg-white px-3 py-1 text-xs font-medium text-zinc-600 transition-colors hover:bg-zinc-50">View All</a>
                </div>

                <div class="overflow-x-auto p-3">
                    <table class="w-full text-left text-[11px] admin-table admin-table-compact">
                        <thead class="">
                            <tr>
                                <th class="">User</th>
                                <th class="">Subject</th>
                                <th class="">Status</th>
                                <th class="text-right">Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($latestTickets as $ticket)
                                <tr>
                                    <td class="px-5 py-3 font-medium text-zinc-900">{{ $ticket->name }}</td>
                                    <td class="px-5 py-3 text-zinc-600 truncate max-w-[150px]">{{ $ticket->subject ?: 'No Subject' }}</td>
                                    <td class="px-5 py-3">
                                        @if($ticket->status === 'new')
                                            <span class="inline-flex items-center rounded-full bg-blue-50 px-2.5 py-0.5 text-[11px] font-semibold text-blue-700">New</span>
                                        @elseif($ticket->status === 'read')
                                            <span class="inline-flex items-center rounded-full bg-zinc-50 px-2.5 py-0.5 text-[11px] font-semibold text-zinc-600">Read</span>
                                        @else
                                            <span class="inline-flex items-center rounded-full bg-green-50 px-2.5 py-0.5 text-[11px] font-semibold text-green-700">Resolved</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3 text-right text-zinc-500">{{ $ticket->created_at->diffForHumans() }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-5 py-8 text-center text-zinc-500">No support tickets found.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Latest Users --}}
            <div class="min-w-0 overflow-hidden rounded-[12px] bg-white shadow-sm shadow-zinc-900/5">
                <div class="flex items-center justify-between border-b border-zinc-200 p-5">
                    <div class="flex items-center gap-2">
                        <svg class="h-5 w-5 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                        </svg>
                        <h2 class="text-base font-semibold text-zinc-900">Latest Users</h2>
                    </div>
                    <a href="{{ route('admin.customers') }}" class="rounded-[12px] border border-zinc-200 bg-white px-3 py-1 text-xs font-medium text-zinc-600 transition-colors hover:bg-zinc-50">View All</a>
                </div>

                <div class="overflow-x-auto p-3">
                    <table class="w-full text-left text-[11px] admin-table admin-table-compact">
                        <thead class="">
                            <tr>
                                <th class="">User</th>
                                <th class="">Status</th>
                                <th class="text-right">Registered</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($latestUsers as $user)
                                <tr>
                                    <td class="px-5 py-3">
                                        <div class="flex items-center gap-3">
                                            @php
                                                $rowAvatar = $user->avatar_url ?: $user->initialsAvatar();
                                            @endphp
                                            <img src="{{ $rowAvatar }}" alt="" class="h-9 w-9 shrink-0 rounded-[12px] object-cover ring-1 ring-blue-100">
                                            <div class="leading-tight">
                                                <p class="text-[11px] font-semibold text-zinc-900">{{ $user->name }}</p>
                                                <p class="text-[10px] text-zinc-600">{{ $user->email }}</p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-5 py-3">
                                        @if ($user->banned_at)
                                            <span class="inline-flex items-center rounded-full bg-red-50 px-2.5 py-0.5 text-[11px] font-semibold text-red-700">Banned</span>
                                        @elseif ($user->suspended_at)
                                            <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-[11px] font-semibold text-amber-700">Suspended</span>
                                        @else
                                            <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-[11px] font-semibold text-emerald-700">Active</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3 text-right text-zinc-500">{{ $user->created_at->diffForHumans() }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="px-5 py-8 text-center text-zinc-500">No users found.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>
