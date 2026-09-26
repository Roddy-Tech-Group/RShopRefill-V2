<?php

/**
 * Nav bars, the category rail and the footer. Category labels live here rather
 * than in products.php because these are the NAVIGATION labels - the catalogue's
 * own category names come from the database.
 */
return [
    'popular_in' => 'Popolari in :country',
    'categories' => 'Categorie',
    'featured' => 'In evidenza',
    'gift_cards' => 'Carte regalo',
    'esims' => 'eSIM',
    'mobile_refill' => 'Ricarica mobile',
    'bill_payments' => 'Bollette',
    'flights' => 'Voli',
    'stays' => 'Soggiorni',
    'search_placeholder' => 'Cerca marchi, categorie, paesi',
    'sign_in' => 'Accedi',
    'cart' => 'Carrello',
    'account' => 'Account',
    'my_orders' => 'I miei ordini',
    'rcoin_rewards' => 'Premi Rcoin',
    'settings' => 'Impostazioni',
    'limits' => 'Limiti',
    'logout' => 'Esci',
    'shop' => 'Negozio',
    'company' => 'Azienda',
    'legal' => 'Note legali',
    'get_the_app' => 'Scarica l\'app',
    'follow_us' => 'Seguici',
    'help_center' => 'Aiuto',
    'faqs' => 'FAQ',
    'contact_us' => 'Contattaci',
    'how_it_works' => 'Come funziona',
    'earn_points' => 'Guadagna punti',
    'terms_of_service' => 'Termini di servizio',
    'privacy_policy' => 'Informativa sulla privacy',
    'cookie_policy' => 'Informativa sui cookie',
    'compliance' => 'Conformità',
    'refund_policy' => 'Politica di rimborso',
    'accessibility' => 'Accessibilità',
    'sitemap' => 'Mappa del sito',

    // Region suggestion popup under the header's region pill.
    'region_suggest_body' => 'Sta vedendo prodotti utilizzabili in :country. Visualizzi i prodotti per:',
    'region_suggest_switch' => 'Cambia',
    'region_suggest_dismiss' => 'Chiudi il suggerimento di regione',

    // Header category rail and the phone menu's Mobile Refill page.
    'product_categories' => 'Categorie di prodotti',

    // Region pill. :language is filled on the server; :country and :currency
    // in the browser, where the live values are.
    'region_pill_label' => 'Regione e valuta: :country, :language, :currency. Modifica',

    // Search panel. The type labels say what a result row IS.
    'search_no_results' => 'Nessun risultato per “:query”',
    'search_group_bill_payments' => 'Pagamento bollette',
    'search_group_suggestions' => 'Suggerimenti',
    'search_type_gift_card' => 'Carta regalo',
    'search_type_topup' => 'Ricarica telefonica',
    'search_type_bill' => 'Pagamento bolletta',
    'search_type_esim' => 'eSIM',
    'search_type_page' => 'Pagina',

    // Account chip and the cart popup. :amount is a formatted price.
    'your_account' => 'Il tuo account',
    'cart_decrease' => 'Diminuisci',
    'cart_increase' => 'Aumenta',
    'cart_unit_card' => 'Carta da :amount',
    'cart_unit_esim' => 'eSIM da :amount',
    'cart_unit_topup' => 'Ricarica da :amount',
    'cart_unit_bill' => 'Bolletta da :amount',

    // Referral panel (the nav's Invite a Friend pill and the dashboard top bar).
    'referral_title' => 'Invita gli amici, guadagna Rcoin',
    'referral_title_up_to' => 'Invita gli amici, guadagna fino a :amount in Rcoin',
    'referral_body_any_order' => 'Condividi il tuo link. Guadagni Rcoin quando una persona che hai invitato completa un ordine. La sola registrazione non fa guadagnare nulla.',
    'referral_body_first_order' => 'Condividi il tuo link. Guadagni Rcoin quando una persona che hai invitato completa il suo primo ordine. La sola registrazione non fa guadagnare nulla.',
    'referral_see_rewards' => 'Vedi le tue ricompense Rcoin',
    'referral_sign_in' => 'Accedi per ottenere il tuo link',
    'referral_new_here' => 'Sei nuovo? :link e il tuo link sarà subito pronto.',
    'referral_create_account' => 'Crea un account',

    // Cookie banner. :site is the site name, :policy the Cookie Policy link.
    'cookie_consent' => 'Consenso ai cookie',
    'cookie_body' => 'Usiamo i cookie per mantenerti connesso, proteggere i tuoi pagamenti e migliorare :site. Consulta la nostra :policy.',
    'cookie_decline' => 'Rifiuta',
    'cookie_accept' => 'Accetta i cookie',

    // Feedback widget.
    'feedback_region' => 'Feedback sul sito',
    'feedback_tab' => 'Feedback',
    'feedback_tab_label' => 'Inviaci un feedback. Trascina per spostare o usa i tasti freccia.',
    'feedback_drag' => 'Trascina per spostare',
    'feedback_title' => 'Invia feedback',
    'feedback_email_placeholder' => 'La tua email',
    'feedback_message_placeholder' => 'Il tuo feedback...',
    'feedback_send' => 'Invia',
    'feedback_sending' => 'Invio in corso...',
    'feedback_thanks' => 'Grazie per il feedback',
    'feedback_ticket_opened' => 'È stato aperto un ticket con il team.',
    'feedback_send_another' => 'Inviane un altro',
    'feedback_security_failed' => 'Verifica di sicurezza non riuscita. Riprova.',

    // Currency names in the preferences sheet. The codes beside them are not copy.
    'currency_names' => [
        'USD' => 'Dollaro statunitense',
        'EUR' => 'Euro',
        'GBP' => 'Sterlina britannica',
        'NGN' => 'Naira nigeriana',
        'XAF' => 'Franco CFA dell’Africa centrale',
        'ZAR' => 'Rand sudafricano',
        'KES' => 'Scellino keniota',
        'GHS' => 'Cedi ghanese',
        'EGP' => 'Sterlina egiziana',
        'MAD' => 'Dirham marocchino',
        'CAD' => 'Dollaro canadese',
        'AUD' => 'Dollaro australiano',
        'JPY' => 'Yen giapponese',
        'CNY' => 'Yuan cinese',
        'INR' => 'Rupia indiana',
        'BRL' => 'Real brasiliano',
        'AED' => 'Dirham degli Emirati Arabi Uniti',
        'SAR' => 'Riyal saudita',
        'TRY' => 'Lira turca',
        'CHF' => 'Franco svizzero',
    ],
];
