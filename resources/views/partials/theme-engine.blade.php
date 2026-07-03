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
        // Mirror the resolved pure-dark (Extra Dark / black) decision into its own
        // cookie too, so the server can paint .pure-dark from the first byte.
        // Customer side defaults to ON when the cookie is absent (see head.blade.php),
        // so the default black page never flashes navy first.
        function writePureDarkCookie(on) {
            const name = location.pathname.startsWith('/admin')
                ? 'theme_admin_puredark'
                : 'theme_web_puredark';
            document.cookie = `${name}=${on ? '1' : '0'}; path=/; max-age=31536000; SameSite=Lax`;
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

        // Extra-dark (true black) is a sub-preference of dark mode, stored per
        // area exactly like the theme key — admin and customer each remember
        // their own choice. Only applies while dark is the resolved theme.
        function pureDarkKey() {
            return location.pathname.startsWith('/admin') ? 'admin.theme.pure_dark' : 'theme.pure_dark';
        }
        function pureDarkOn() {
            const v = localStorage.getItem(pureDarkKey());
            if (v !== null) { return v === '1'; }   // the user's own choice always wins
            // No explicit choice → the default dark palette:
            //  - customer side: pure black IS the default dark mode (navy is the
            //    opt-in "Soft dark" appearance).
            //  - admin side: unchanged — navy by default, unless the admin's
            //    global Extra Dark setting is on.
            if (location.pathname.startsWith('/admin')) { return !! cfg.globalExtraDark; }
            return true;
        }

        function applyTheme() {
            const choice = themeChoice();
            const isDark = resolveDark();
            const pure = pureDarkOn();
            root.classList.toggle('dark', isDark);
            root.classList.toggle('pure-dark', isDark && pure);
            writeCookie(isDark);
            writePureDarkCookie(pure);
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

        // Pure-dark (true black) is the default dark palette; the "Soft dark"
        // appearance toggle stores its inverse here. Local only (no server
        // round-trip); applyTheme re-evaluates the .pure-dark class + cookie.
        window.setPureDark = function (on) {
            try { localStorage.setItem(pureDarkKey(), on ? '1' : '0'); } catch (_) {}
            applyTheme();
            window.dispatchEvent(new CustomEvent('pure-dark-changed', { detail: { on: pureDarkOn() } }));
        };
        window.pureDarkOn = pureDarkOn;

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
