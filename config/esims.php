<?php

return [

    /*
    | The destinations that appear under the eSIM store's "Popular" tab, as ISO
    | 3166-1 alpha-2 codes, in no particular order (the grid keeps the
    | catalogue's own order).
    |
    | A country listed here only shows up if we actually sell an eSIM for it and
    | it has a flag, so adding a code the catalogue does not carry is harmless:
    | the tab simply will not show it. Removing one does not hide the
    | destination either, it just stops it being "popular" and leaves it under
    | Local, Regional or the full list.
    |
    | The USA is deliberately absent. The pinned "Global eSIM" card, a real +1
    | number with worldwide coverage, fills that slot on this tab.
    |
    | e.g. ESIM_POPULAR_COUNTRIES=CM,FR,GB,NG
    */
    'popular_countries' => array_values(array_filter(array_map(
        fn (string $code) => strtoupper(trim($code)),
        explode(',', (string) env(
            'ESIM_POPULAR_COUNTRIES',
            'CM,FR,GB,CG,MA,CF,EG,IN,CI,CD,SN,GH,GA,KE,AZ,AU,ZA,TR,TH'
        ))
    ))),

];
