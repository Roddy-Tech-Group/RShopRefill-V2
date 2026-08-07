@php
    $session = $recovery->paymentSession;
    $order = $session?->order;
    $items = $order?->items ?? collect();
@endphp

<x-layouts.app.header title="Complete your payment">
    <div class="flex min-h-screen items-center justify-center bg-zinc-50 py-12 px-4 sm:px-6 lg:px-8">
        <div class="w-full max-w-md space-y-6 bg-white p-8 rounded-2xl shadow-xl">
            <div>
                <h2 class="text-center text-2xl font-extrabold text-zinc-900">{{ $copy['headline'] }}</h2>
                <p class="mt-3 text-center text-sm leading-relaxed text-zinc-600">{{ $copy['guidance'] }}</p>
                @if ($copy['hint'])
                    <p class="mt-2 text-center text-sm leading-relaxed text-zinc-500">{{ $copy['hint'] }}</p>
                @endif
            </div>

            {{-- What is waiting: the decision to finish is made on the contents,
                 not on the fact that a payment failed. --}}
            @if ($order || $session)
                <div class="rounded-xl bg-zinc-50 p-4">
                    @if ($order)
                        <p class="text-xs text-zinc-500">Order {{ $order->order_number }}</p>
                    @endif
                    @if ($items->isNotEmpty())
                        <ul class="mt-1 space-y-0.5">
                            @foreach ($items->take(3) as $item)
                                <li class="text-sm font-semibold text-zinc-900">{{ $item->product_snapshot['name'] ?? 'Item' }}</li>
                            @endforeach
                            @if ($items->count() > 3)
                                <li class="text-xs text-zinc-500">and {{ $items->count() - 3 }} more</li>
                            @endif
                        </ul>
                    @endif
                    @if ($session?->amount)
                        <p class="mt-2 text-lg font-extrabold text-zinc-900">
                            @moneyCode((float) $session->amount, $session->display_currency ?: $session->currency)
                        </p>
                    @endif
                </div>
            @endif

            <div class="space-y-6">
                <div>
                    <form method="POST" action="{{ route('recovery.retry', $recovery->token) }}">
                        @csrf
                        <button type="submit" class="group relative flex w-full justify-center rounded-md border border-transparent bg-blue-600 py-3 px-4 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                            Complete your payment
                        </button>
                    </form>
                    <p class="mt-2 text-center text-xs text-zinc-500">
                        This takes you back to checkout. Nothing has been charged.
                    </p>
                </div>

                <div class="relative">
                    <div class="absolute inset-0 flex items-center">
                        <div class="w-full border-t border-zinc-300"></div>
                    </div>
                    <div class="relative flex justify-center text-sm">
                        <span class="bg-white px-2 text-zinc-500">OR</span>
                    </div>
                </div>

                <!-- Action 2: Provide Feedback -->
                <form class="space-y-4" action="{{ route('recovery.feedback', $recovery->token) }}" method="POST">
                    @csrf
                    <div>
                        <label class="text-sm font-medium text-zinc-700">Tell us what happened:</label>
                        <div class="mt-2 space-y-2">
                            <div class="flex items-center">
                                <input id="reason_1" name="reason" type="radio" value="declined" class="h-4 w-4 border-zinc-300 text-blue-600 focus:ring-blue-500" required>
                                <label for="reason_1" class="ml-3 block text-sm font-medium text-zinc-700">My card/payment was declined</label>
                            </div>
                            <div class="flex items-center">
                                <input id="reason_2" name="reason" type="radio" value="technical_issue" class="h-4 w-4 border-zinc-300 text-blue-600 focus:ring-blue-500" required>
                                <label for="reason_2" class="ml-3 block text-sm font-medium text-zinc-700">I faced a technical issue or error</label>
                            </div>
                            <div class="flex items-center">
                                <input id="reason_3" name="reason" type="radio" value="changed_mind" class="h-4 w-4 border-zinc-300 text-blue-600 focus:ring-blue-500" required>
                                <label for="reason_3" class="ml-3 block text-sm font-medium text-zinc-700">I changed my mind</label>
                            </div>
                            <div class="flex items-center">
                                <input id="reason_4" name="reason" type="radio" value="other" class="h-4 w-4 border-zinc-300 text-blue-600 focus:ring-blue-500" required>
                                <label for="reason_4" class="ml-3 block text-sm font-medium text-zinc-700">Other</label>
                            </div>
                        </div>
                    </div>

                    <div>
                        <label for="details" class="block text-sm font-medium text-zinc-700">Additional Details (Optional)</label>
                        <textarea id="details" name="details" rows="3" class="mt-1 block w-full rounded-md border-zinc-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm" placeholder="Any extra information..."></textarea>
                    </div>

                    <button type="submit" class="flex w-full justify-center rounded-md border border-zinc-300 bg-white py-2 px-4 text-sm font-medium text-zinc-700 shadow-sm hover:bg-zinc-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                        Submit Feedback
                    </button>
                </form>
            </div>
        </div>
    </div>
</x-layouts.app.header>
