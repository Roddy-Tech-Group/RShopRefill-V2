{{--
    An icon from public/assets drawn INLINE, so it can animate when its link or
    button is hovered.

    The dashboard used these same files as <img> tags and CSS masks, which
    cannot be animated or recoloured from inside. This reads the file itself,
    so the artwork is identical, and hands it to the page as markup:

      - black fills and strokes become currentColor, so the icon takes the
        text colour around it. tone="mono" (the default) sets text-black, which
        is what the old brightness(0) filters produced, and app.css already
        remaps .dark .text-black to near-white, which is what the old dark-mode
        img invert produced. tone="inherit" leaves the colour to the parent
        (the header cart chip, the tab bar), tone="white" is for coloured tiles.
      - ids, <desc> and the fixed width/height are stripped, so the same icon
        can appear many times on a page and sizes from its class.

    Each file maps to a motion that suits it (the bell rings, the gear spins,
    the cart rolls). The keyframes live in app.css under "Animated icons" and
    fire when any ancestor link or button is hovered or keyboard-focused, and
    not at all under prefers-reduced-motion.

    Usage: <x-ui.anim-icon file="new cart.svg" class="h-5 w-5" />
--}}
@props([
    'file',
    'tone' => 'mono',
])

@php
    $motion = match ($file) {
        'new cart.svg' => 'cart',
        'notification 2.svg' => 'ring',
        'Wallet.svg', 'wallet 2.svg' => 'pop',
        'order.svg', 'Home.svg' => 'bounce',
        'transactions.svg', 'transactions 1.svg', 'Appearance.svg', 'system setting.svg' => 'spin',
        'Shop.svg' => 'jiggle',
        'customer.svg' => 'tilt',
        'admin access.svg' => 'shake',
        'support.svg' => 'wiggle',
        'user.svg', 'for-partners.svg' => 'nod',
        'Back to shop.svg' => 'nudge',
        'Hamburger menu.svg' => 'squeeze',
        'savedcard.svg' => 'flip',
        default => 'pop',
    };

    // A bare filename only: this reads from disk, so no path may climb out of assets/.
    $iconPath = basename($file) === $file ? public_path('assets/'.$file) : null;

    $iconSvg = '';

    if ($iconPath && is_file($iconPath)) {
        // One cache read per icon per REQUEST, not per render. The dashboard
        // paints this component ~40 times, and on the database cache store a
        // rememberForever() hit is still a SELECT each time, so the icons alone
        // were 40 of the shell's 101 queries. The container holds the memo for
        // the life of the request.
        $iconKey = 'anim-icon:v1:'.md5($file).':'.filemtime($iconPath);
        $iconMemo = app()->bound('anim-icon.memo') ? app('anim-icon.memo') : app()->instance('anim-icon.memo', new \ArrayObject);

        $iconSvg = $iconMemo[$iconKey] ??= \Illuminate\Support\Facades\Cache::rememberForever(
            $iconKey,
            function () use ($iconPath) {
                $svg = (string) file_get_contents($iconPath);
                $svg = preg_replace('/<\?xml.*?\?>|<!--.*?-->|<desc>.*?<\/desc>|<title>.*?<\/title>/s', '', $svg);
                $svg = preg_replace('/\s(?:id|data-name)="[^"]*"/', '', $svg);
                $svg = preg_replace('/\b(fill|stroke)="(?:#000000|#000|black)"/i', '$1="currentColor"', $svg);

                return trim(preg_replace_callback('/<svg\b[^>]*>/', fn (array $root) => preg_replace(
                    ['/\s(?:width|height)="[^"]*"/', '/^<svg/'],
                    ['', '<svg aria-hidden="true" focusable="false"'],
                    $root[0]
                ), $svg, 1));
            }
        );
    }

    $toneClass = match ($tone) {
        'inherit' => '',
        'white' => 'text-white',
        default => 'text-black',
    };
@endphp

<span {{ $attributes->class(['anim-icon inline-flex shrink-0', 'anim-icon--'.$motion, $toneClass]) }} aria-hidden="true">{!! $iconSvg !!}</span>
