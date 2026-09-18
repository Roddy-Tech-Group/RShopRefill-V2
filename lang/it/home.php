<?php

/**
 * The home page's own copy.
 *
 * Separate from products.php and navigation.php: those name things the whole
 * site refers to, this is marketing prose that only the landing page says.
 * Keeping it apart means a copy rewrite here cannot quietly change a label
 * the catalogue depends on.
 */
return [
    // Hero. The headline is two lines on a wide screen and the break sits
    // between them, so it is stored as two keys rather than one string with
    // markup in it - a translator should never have to carry a <br> across.
    'hero_heading_line_one' => 'Carte regalo, eSIM, ricariche mobili',
    'hero_heading_line_two' => '& pagamento bollette, tutto in un posto',
    'hero_subtitle' => 'Paghi facilmente con Mobile Money, Orange Money, carta, bonifico bancario o crypto tutti i suoi acquisti digitali, le ricariche degli abbonamenti e le bollette.',
    'hero_cta_gift_cards' => 'Acquista carte regalo',
    'hero_cta_esims' => 'Scopri le eSIM',

    // The feature chips under the hero. :count is the rounded catalogue figure,
    // so the number stays live while the sentence around it is translatable.
    'chip_products_title' => ':count+ prodotti digitali',
    'chip_products_subtitle' => 'Carte regalo, eSIM, ricariche e pagamento bollette in tutto il mondo',
    'chip_countries_title' => ':count+ paesi coperti',
    'chip_countries_subtitle' => 'eSIM da viaggio e ricariche locali in oltre :count destinazioni',
    'chip_reviews_title' => 'Recensioni verificate dei clienti',
    'chip_reviews_subtitle' => 'Opinioni reali di chi ha acquistato qui',
    'chip_devices_title' => 'Funziona su ogni dispositivo',
    'chip_devices_subtitle' => 'Compatibile con laptop, iPad e cellulare',
    'chip_search_title' => 'Trovi subito ciò che le serve',
    'chip_search_subtitle' => 'Cerchi carte regalo, eSIM e ricariche in pochi secondi',

    // Assurance strip under the category cards: three promises and the
    // payment methods that back them.
    'assurance_label' => 'Cosa ottiene e come pagare',
    'assurance_instant_title' => 'Consegna immediata',
    'panel_title' => 'Consegna immediata, nessun problema.',
    'assurance_privacy_title' => 'Sicuro per progettazione',
    'assurance_support_title' => 'Assistenza umana 24 ore su 24',
    'assurance_pay_with' => 'Paghi con MOMO, OM, carte e cripto',
    'assurance_more_methods' => '+:count altri',

    // Homepage FAQ. Questions themselves live in the faqs table.
    'faq_heading' => 'Domande frequenti',
    'faq_subheading' => 'Consulti la nostra pagina :link per altre risposte',
    'faq_link_label' => 'FAQ',

    // Live counts panel. The numbers come from the database; these are
    // only the labels beside them.
    'counts_customers' => 'Clienti registrati',
    'counts_orders' => 'Ordini consegnati',
    'counts_countries' => 'Paesi serviti',
    'counts_rank_value' => 'Affidabile',
    'counts_rank_label' => 'Piattaforma di commercio digitale',
];
