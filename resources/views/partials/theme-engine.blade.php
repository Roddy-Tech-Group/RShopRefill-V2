{{-- Theme engine — light / dark / system. Shared by every layout's <head>.
     Resolves the theme choice (default 'system') and toggles the `.dark` class
     on <html> BEFORE first paint, so there is no light/dark flash.
     Re-applies after every wire:navigate DOM swap, and follows the OS in real
     time while the choice is 'system'. `window.setTheme(choice)` is called by
     the settings theme picker and the <x-theme-toggle> buttons.

     Per-account: the signed-in account's saved theme (users.theme / admins.theme)
     is rendered below and is authoritative — it primes localStorage so the
     preference follows the account onto any device, and every toggle change is
     POSTed back so it persists. Guests fall back to localStorage + the OS.

     The admin panel keeps its own theme independent of the customer side: pages
     under /admin use the 'admin.theme' storage key + the admin endpoint;
     everything else uses 'theme' + the customer endpoint. The key is resolved
     per-call from the current path so it stays correct across wire:navigate.
     Read the current choice via window.themeChoice(). --}}
@php
    // Resolve the account + persistence endpoint for the area being rendered.
    // Path-based (not just guard-based) so it matches the JS storage key even
    // when an admin and a customer are signed in on the same browser.
    $themeIsAdminArea = request()->is('admin*');
    $themeAccount = $themeIsAdminArea ? auth('admin')->user() : auth('web')->user();
    $themeServerChoice = $themeAccount?->theme;
    $themeEndpoint = $themeAccount
        ? ($themeIsAdminArea ? route('admin.theme') : route('preferences.theme'))
        : null;

    // Admin-controlled global default (System Settings -> system.global_extra_dark).
    // When on, every user falls back to Extra Dark unless they have set their own
    // theme / Extra Dark choice. Cached read (SiteSetting::get uses Cache::remember).
    $themeGlobalExtraDark = \App\Models\SiteSetting::get('system.global_extra_dark', 'off') === 'on';
@endphp
<script>
    (function () {
        const root = document.documentElement;

        // Account theme + endpoint for this area (null for guests → local only).
        const cfg = {
            server: @json($themeServerChoice),
            endpoint: @json($themeEndpoint),
            globalExtraDark: @json($themeGlobalExtraDark),
            csrf: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
        };

        // Admin and customer areas share an origin (and thus localStorage), so
        // each gets its own key — toggling one must never affect the other.
        function themeKey() {
            return location.pathname.startsWith('/admin') ? 'admin.theme' : 'theme';
        }
        function themeChoice() {
            const v = localStorage.getItem(themeKey());
            if (v) { return v; }
            // No explicit choice: when the admin has switched on the global Extra
            // Dark default, unconfigured users start in dark so pure-dark can show.
            return cfg.globalExtraDark ? 'dark' : 'system';
        }

        function prefersDark() {
            return window.matchMedia('(prefers-color-scheme: dark)').matches;
        }
        function resolveDark() {
            const choice = themeChoice();
            if (choice === 'dark') return true;
            if (choice === 'light') return false;
            return prefersDark();
        }
        // Mirror the resolved dark/light decision into a cookie so the
        // server can render the next request with the right .dark class +
        // color-scheme meta from the very first byte. Two cookies (web and
        // admin) so the customer side never leaks the admin's preference.
        function writeCookie(isDark) {
            const name = location.pathname.startsWith('/admin')
                ? 'theme_admin_dark'
                : 'theme_web_dark';
            // 1 year, root path, lax so it travels on top-level navigations
            // but not third-party iframes. Not HttpOnly - JS writes it.
            document.cookie = `${name}=${isDark ? '1' : '0'}; path=/; max-age=31536000; SameSite=Lax`;
        }

        // Flux UI ships its own theme engine (loaded by the appearance
        // directive in the head) that runs AFTER our script. It reads
        // `flux.appearance` from localStorage and falls back to OS
        // preference - so if our app is set to LIGHT but the OS is dark,
        // Flux would flip the page to dark on every load, then our next
        // applyTheme would flip it back. The visible result is a
        // dark-then-light blink on hard refresh. Writing our choice into
        // Flux's own storage key keeps both engines reading the same
        // value and stops the war.
        //
        // NOTE: do not mention the literal Blade directive name (with the
        // leading at-sign) inside this script body. Blade's tokenizer
        // still matches @-prefixed directives inside HTML script blocks
        // and will splice the directive's output right in the middle of
        // this comment, breaking the surrounding script tag.
        function syncFlux(choice) {
            try { localStorage.setItem('flux.appearance', choice); } catch (_) {}
        }

        // Soft dark has been removed: there is one dark palette now, the grainy
        // black. `.pure-dark` is still the class the palette hangs off in
        // app.css, so it goes on unconditionally whenever dark resolves, and
        // this always answers true. Kept as a function rather than inlined so
        // window.pureDarkOn stays a valid call for anything still asking.
        function pureDarkOn() {
            return true;
        }

        // Anyone carrying the retired soft-dark preference is moved to the new
        // dark on their next load, and the stale key and cookie are cleared so
        // nothing reads them again.
        function clearRetiredSoftDark() {
            try {
                localStorage.removeItem('theme.pure_dark');
                localStorage.removeItem('admin.theme.pure_dark');
            } catch (_) {}
            document.cookie = 'pure_dark=; Max-Age=0; path=/; SameSite=Lax';
        }

        function applyTheme() {
            const choice = themeChoice();
            const isDark = resolveDark();
            root.classList.toggle('dark', isDark);
            root.classList.toggle('pure-dark', isDark);
            writeCookie(isDark);
            syncFlux(choice);
        }

        // Persist the choice to the signed-in account so it follows them across
        // devices. Guests have no endpoint and stay local-only.
        function persist(choice) {
            if (! cfg.endpoint || ! cfg.csrf) {
                return;
            }
            fetch(cfg.endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': cfg.csrf,
                },
                body: JSON.stringify({ theme: choice }),
                keepalive: true,
            }).catch(function () {});
        }

        // Theme picker / toggles call this to persist + apply a new choice.
        window.setTheme = function (choice) {
            try { localStorage.setItem(themeKey(), choice); } catch (_) {}
            applyTheme();
            // Let any on-page toggle re-sync its icon.
            window.dispatchEvent(new CustomEvent('theme-changed', { detail: { dark: resolveDark() } }));
            persist(choice);
        };
        window.themeIsDark = resolveDark;
        window.themeChoice = themeChoice;

        // Retained as no-ops so any stale bundle or cached view that still calls
        // these cannot throw. There is only one dark palette to select now.
        window.setPureDark = function () {};
        window.pureDarkOn = pureDarkOn;
        clearRetiredSoftDark();

        // The account's saved theme wins over a stale cache from another login.
        if (cfg.server) {
            try { localStorage.setItem(themeKey(), cfg.server); } catch (_) {}
        }

        applyTheme(); // before first paint — no flash

        // Track the OS while the choice is 'system'.
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
            if (themeChoice() === 'system') {
                applyTheme();
                window.dispatchEvent(new CustomEvent('theme-changed', { detail: { dark: resolveDark() } }));
            }
        });

        // Re-assert the theme after every wire:navigate DOM swap. The
        // page-transition animation is owned by partials/head.blade.php
        // (one source of truth so it never races with itself); we only
        // touch `.dark` here so theme + transition stay decoupled.
        document.addEventListener('livewire:navigated', applyTheme);
    })();
</script>
