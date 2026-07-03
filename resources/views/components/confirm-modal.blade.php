{{--
    Global confirm modal. Include ONCE per layout (admin, dashboard, app header).
    Any form or button with a `data-confirm="..."` attribute is intercepted: a
    custom modal opens with the message + a tone-coloured confirm button, and
    the original action only runs if the admin/user clicks Confirm.

    Usage examples:
        <form method="POST" action="..." data-confirm="Delete this row?"
              data-confirm-title="Delete row" data-confirm-tone="danger"
              data-confirm-text="Delete">
            ...
        </form>

        <button type="button" data-confirm="Are you sure?" @click="doThing()">
            Do thing
        </button>

    Supported `data-confirm-*` attributes (all optional):
        - data-confirm-title    Custom heading. Defaults to "Are you sure?"
        - data-confirm-text     Confirm button label. Defaults to "Confirm"
        - data-confirm-cancel   Cancel button label. Defaults to "Cancel"
        - data-confirm-tone     'danger' | 'warning' | 'primary' | 'success'

    The wiring (event interception + replay) lives in resources/js/app.js under
    the `confirmModalDispatcher` factory — Alpine just renders the chrome and
    listens for the `confirm:show` window event the dispatcher fires.
--}}
<div
    x-data="confirmModal()"
    x-cloak
    x-show="isOpen"
    @keydown.escape.window="cancel()"
    @confirm-show.window="open($event.detail)"
    class="fixed inset-0 z-[100] flex items-center justify-center p-4"
    role="dialog"
    aria-modal="true"
    :aria-labelledby="isOpen ? 'confirm-modal-title' : null"
>
    {{-- Backdrop --}}
    <div
        x-show="isOpen"
        x-transition.opacity
        @click="cancel()"
        class="absolute inset-0 bg-zinc-900/50 dark:bg-zinc-950/70"
    ></div>

    {{-- Panel --}}
    <div
        x-show="isOpen"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        class="relative w-full max-w-md overflow-hidden rounded-[12px] bg-[#eff6ff] shadow-2xl ring-1 ring-zinc-200 dark:ring-[#24364f]"
    >
        <div class="px-5 py-4">
            <div class="flex items-start gap-3">
                {{-- Tone-coloured icon. Reads `tone` off the Alpine component. --}}
                <span
                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[12px]"
                    :class="{
                        'bg-red-100 text-red-600 dark:bg-red-500/15 dark:text-red-300': tone === 'danger',
                        'bg-amber-100 text-amber-600 dark:bg-amber-500/15 dark:text-amber-300': tone === 'warning',
                        'bg-blue-100 text-blue-600 dark:bg-blue-500/15 dark:text-blue-300': tone === 'primary',
                        'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-300': tone === 'success',
                    }"
                >
                    <svg x-show="tone === 'danger' || tone === 'warning'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.732 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
                    </svg>
                    <svg x-show="tone === 'primary'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/>
                    </svg>
                    <svg x-show="tone === 'success'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </span>

                <div class="min-w-0 flex-1">
                    <h3 id="confirm-modal-title" class="text-base font-bold text-zinc-900 dark:text-white" x-text="title"></h3>
                    <p class="mt-1 text-sm leading-relaxed text-zinc-600 dark:text-zinc-300" x-text="message"></p>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-end gap-2 border-t border-zinc-100 px-5 py-3 dark:border-zinc-700/60">
            <button
                type="button"
                @click="cancel()"
                class="inline-flex items-center rounded-[12px] px-3.5 py-2 text-xs font-semibold text-zinc-700 transition-colors hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-[#26416b]"
                x-text="cancelText"
            ></button>
            <button
                type="button"
                @click="confirm()"
                x-ref="confirmBtn"
                class="inline-flex items-center rounded-[12px] px-4 py-2 text-xs font-semibold text-white transition-colors"
                :class="{
                    'bg-red-600 hover:bg-red-700': tone === 'danger',
                    'bg-amber-600 hover:bg-amber-700': tone === 'warning',
                    'bg-blue-600 hover:bg-blue-700': tone === 'primary',
                    'bg-emerald-600 hover:bg-emerald-700': tone === 'success',
                }"
                x-text="confirmText"
            ></button>
        </div>
    </div>
</div>
