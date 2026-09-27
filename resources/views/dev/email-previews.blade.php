<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Email template previews - RshopRefill</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'Inter', 'Helvetica Neue', Arial, sans-serif; background: #f4f6f5; color: #18181b; height: 100vh; display: grid; grid-template-columns: 280px 1fr; transition: grid-template-columns .2s ease; }
        body.nav-hidden { grid-template-columns: 0 1fr; }
        aside { background: #0c1a2e; color: #fff; padding: 20px 16px; overflow-y: auto; overflow-x: hidden; white-space: nowrap; }
        .brand { font-size: 15px; font-weight: 800; letter-spacing: .02em; margin: 0 0 4px; }
        .brand span { color: #ffffff; }
        .hint { font-size: 11px; color: #94a3b8; margin: 0 0 20px; line-height: 1.5; white-space: normal; }
        .group { font-size: 10px; text-transform: uppercase; letter-spacing: .08em; color: #64748b; margin: 18px 0 6px; font-weight: 700; }
        a.tpl { display: block; padding: 9px 12px; border-radius: 8px; color: #e2e8f0; text-decoration: none; font-size: 13px; font-weight: 500; transition: background .15s, color .15s; }
        a.tpl:hover { background: #1e293b; }
        a.tpl.active { background: #2563eb; color: #fff; font-weight: 600; }
        main { display: flex; flex-direction: column; min-width: 0; }
        .bar { display: flex; align-items: center; gap: 12px; padding: 10px 16px; background: #fff; border-bottom: 1px solid #e2e8f0; }
        .bar h1 { font-size: 14px; font-weight: 700; margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .icon-btn { flex: none; display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; border: 1px solid #e2e8f0; border-radius: 8px; background: #fff; color: #334155; cursor: pointer; font-size: 16px; line-height: 1; }
        .icon-btn:hover { background: #f1f5f9; }
        .seg { margin-left: auto; display: inline-flex; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; flex: none; }
        .seg button { border: 0; background: #fff; padding: 6px 12px; font-size: 12px; font-weight: 600; color: #475569; cursor: pointer; }
        .seg button.active { background: #2563eb; color: #fff; }
        .open { font-size: 12px; color: #2563eb; text-decoration: none; font-weight: 600; flex: none; white-space: nowrap; }
        .frame-wrap { flex: 1; padding: 24px; overflow: auto; display: flex; justify-content: center; align-items: stretch; }
        iframe { width: 100%; height: 100%; border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; box-shadow: 0 4px 20px -8px rgba(0,0,0,.15); }
        .frame-wrap.mobile iframe { width: 390px; max-width: 100%; flex: none; }
        /* On small screens, start with the sidebar collapsed so the preview is usable. */
        @media (max-width: 760px) {
            body { grid-template-columns: 0 1fr; }
            body.nav-shown { grid-template-columns: 240px 1fr; }
            .open { display: none; }
        }
    </style>
</head>
<body>
    <aside>
        <p class="brand">RShop<span>Refills</span> Emails</p>
        <p class="hint">Local preview of every transactional email, rendered with sample data. No mail is sent.</p>

        @php $first = true; @endphp
        @foreach ($groups as $groupName => $items)
            <div class="group">{{ $groupName }}</div>
            @foreach ($items as $item)
                <a class="tpl {{ $first ? 'active' : '' }}"
                   href="{{ route('dev.emails.show', $item['key']) }}"
                   target="previewFrame"
                   data-key="{{ $item['key'] }}"
                   onclick="setActive(this)">{{ $item['label'] }}</a>
                @php $first = false; @endphp
            @endforeach
        @endforeach
    </aside>

    <main>
        <div class="bar">
            <button class="icon-btn" onclick="toggleNav()" title="Show / hide list" aria-label="Toggle template list">&#9776;</button>
            <h1 id="currentLabel">Welcome</h1>
            <div class="seg" role="group" aria-label="Preview width">
                <button id="segDesktop" class="active" onclick="setView('desktop')">Desktop</button>
                <button id="segMobile" onclick="setView('mobile')">Mobile</button>
            </div>
            <a class="open" id="openTab" href="{{ route('dev.emails.show', 'welcome') }}" target="_blank" rel="noopener">Open in new tab &nearr;</a>
        </div>
        <div class="frame-wrap" id="frameWrap">
            <iframe name="previewFrame" id="previewFrame" src="{{ route('dev.emails.show', 'welcome') }}"></iframe>
        </div>
    </main>

    <script>
        const isSmall = () => window.matchMedia('(max-width: 760px)').matches;

        function setActive(link) {
            document.querySelectorAll('a.tpl').forEach(function (a) { a.classList.remove('active'); });
            link.classList.add('active');
            document.getElementById('currentLabel').textContent = link.textContent;
            document.getElementById('openTab').href = link.href;
            // On small screens the list overlays the preview, so close it after picking.
            if (isSmall()) { document.body.classList.remove('nav-shown'); }
        }

        function toggleNav() {
            // Small screens use `nav-shown` (default hidden); larger use `nav-hidden` (default shown).
            document.body.classList.toggle(isSmall() ? 'nav-shown' : 'nav-hidden');
        }

        function setView(mode) {
            const mobile = mode === 'mobile';
            document.getElementById('frameWrap').classList.toggle('mobile', mobile);
            document.getElementById('segMobile').classList.toggle('active', mobile);
            document.getElementById('segDesktop').classList.toggle('active', !mobile);
        }
    </script>
</body>
</html>
