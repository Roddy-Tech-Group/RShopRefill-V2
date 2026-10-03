<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>RShopRefill Mobile API</title>
    <style>
        :root { --ink: #0f172a; --muted: #475569; --line: #e2e8f0; --bg: #ffffff; --accent: #0f4c81; }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--ink); font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        .bar { display: flex; align-items: center; gap: 16px; padding: 12px 24px; border-bottom: 1px solid var(--line); position: sticky; top: 0; background: var(--bg); z-index: 10; }
        .bar strong { font-size: 15px; }
        .bar a { color: var(--accent); text-decoration: none; font-size: 14px; }
        .bar a:hover { text-decoration: underline; }
        .bar .spacer { flex: 1; }
        .guide { display: none; max-width: 820px; margin: 0 auto; padding: 32px 24px 64px; line-height: 1.6; }
        .guide.active { display: block; }
        .guide pre { background: #f8fafc; border: 1px solid var(--line); padding: 12px 16px; overflow-x: auto; border-radius: 6px; font-size: 13px; }
        .guide code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 0.92em; }
        .guide table { border-collapse: collapse; width: 100%; margin: 16px 0; font-size: 14px; }
        .guide th, .guide td { border: 1px solid var(--line); padding: 8px 10px; text-align: left; vertical-align: top; }
        .guide th { background: #f8fafc; }
        .guide h1, .guide h2 { letter-spacing: -0.01em; }
        .guide h2 { margin-top: 40px; border-bottom: 1px solid var(--line); padding-bottom: 6px; }
        #reference.hidden { display: none; }
    </style>
</head>
<body>
    <div class="bar">
        <strong>RShopRefill Mobile API</strong>
        <a href="#" id="show-guide">Guide</a>
        <a href="#" id="show-reference">Reference</a>
        <span class="spacer"></span>
        <a href="{{ $specUrl }}" download="openapi.yaml">Download openapi.yaml</a>
        <a href="{{ route('admin.dashboard') }}">Back to admin</a>
    </div>

    <article class="guide" id="guide">{!! \Illuminate\Support\Str::markdown($guide, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}</article>

    <div id="reference">
        <redoc spec-url="{{ $specUrl }}" hide-download-button expand-responses="200,201" path-in-middle-panel></redoc>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/redoc@2/bundles/redoc.standalone.js"></script>
    <script>
        (function () {
            var guide = document.getElementById('guide');
            var reference = document.getElementById('reference');

            function show(which) {
                guide.classList.toggle('active', which === 'guide');
                reference.classList.toggle('hidden', which === 'guide');
                try { window.localStorage.setItem('api-docs.view', which); } catch (e) {}
            }

            document.getElementById('show-guide').addEventListener('click', function (e) { e.preventDefault(); show('guide'); });
            document.getElementById('show-reference').addEventListener('click', function (e) { e.preventDefault(); show('reference'); });

            var initial = 'reference';
            try { initial = window.localStorage.getItem('api-docs.view') || initial; } catch (e) {}
            show(initial);
        })();
    </script>
</body>
</html>
