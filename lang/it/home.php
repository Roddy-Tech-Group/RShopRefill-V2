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
    'chip_countries_subtitle' => 'eSIM e ricariche locali in oltre :count destinazioni',
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

    // Home rows, the how-it-works steps and the services panel. The
    // offering_* words are joined into sentences with the list glue keys,
    // so a language can set its own separators.
    'esim_row_heading' => 'Resta connesso in oltre :count paesi con le eSIM',
    'esim_row_prev' => 'eSIM precedenti',
    'esim_row_next' => 'Altre eSIM',
    'teaser_flights_and_stays' => 'Voli e soggiorni',
    'teaser_flights_body' => 'Viaggia in modo più intelligente con prenotazioni sicure, conferme rapide e accesso globale da qualsiasi parte del mondo.',
    'teaser_stays_body' => 'Trasforma la valuta digitale in viaggi reali, comfort ed esperienze indimenticabili.',
    'row_featured_gift_cards' => 'Gift card in evidenza',
    'row_popular_gift_cards' => 'Gift card più richieste',
    'row_instant_topups' => 'Ricariche telefoniche immediate',
    'alt_gift_card' => 'Gift card :name',
    'alt_top_up' => 'Ricarica :name',
    'how_heading' => 'Come funziona',
    'how_step1_title' => '1. Scegli un prodotto o un servizio',
    'how_step1_body' => 'Scegli tra oltre :count :offerings, pronti per semplificare la tua esperienza di acquisto.',
    'how_step2_title' => '2. Paga con carta, cripto, Mobile Money, bonifico bancario, ecc.',
    'how_step2_body' => 'Accedi a oltre :count prodotti digitali, tra cui :offerings e altro ancora, tutto in un’unica piattaforma.',
    'how_step3_title' => '3. Ricevilo subito',
    'how_step3_body' => 'Il tuo prodotto arriva in pochi secondi, pronto all’uso, al tuo indirizzo email e nella tua area clienti se sei registrato da noi.',
    'offering_gift_cards' => 'gift card',
    'offering_esims' => 'eSIM',
    'offering_flights' => 'voli',
    'offering_stays' => 'soggiorni',
    'offering_global_stays' => 'soggiorni in tutto il mondo',
    'offering_topups' => 'ricariche telefoniche',
    'offering_bills' => 'pagamento bollette',
    'list_glue' => ', ',
    'list_last_glue' => ' e ',
    'list_last_glue_serial' => ' e ',
    'promo_heading' => 'Cosa offriamo',
    'promo_aria' => 'I nostri servizi',
    'promo_title' => 'Cosa ti offre Rshop',
    'promo_services_count' => 'oltre 23 servizi digitali',
    'promo_access' => 'Accedi a :services in un unico ecosistema potente, tra cui :offerings.',
    'promo_together' => ':site riunisce tutto in un unico posto e rende acquisti internazionali, connettività, viaggi e pagamenti digitali quotidiani veloci, fluidi e senza stress.',
    'promo_tagline_one' => 'Una piattaforma. Possibilità infinite.',
    'promo_tagline_two' => 'Pensata per semplificarti la vita.',
    'promo_copy_manually' => 'Copia manualmente questo link di invito:',
    'promo_copy_link_aria' => 'Copia il tuo link di invito',
    'promo_share_link' => 'Condividi il tuo link di invito per guadagnare RCoin',
    'promo_set_code' => 'Imposta il tuo codice invito per iniziare a guadagnare',
    'promo_open_rewards' => 'Apri premi',
    'promo_guest_title' => 'Crea il tuo account per iniziare a guadagnare RCoin',
    'promo_guest_cta' => 'Crea un account gratuito',
];
