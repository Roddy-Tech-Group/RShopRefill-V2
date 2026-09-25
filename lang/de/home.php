<?php

/**
 * The home page's own copy. See lang/en/home.php for why it is a separate file.
 */
return [
    // Hero. The headline is two lines on a wide screen and the break sits
    // between them, so it is stored as two keys rather than one string with
    // markup in it - a translator should never have to carry a <br> across.
    'hero_heading_line_one' => 'Geschenkkarten, eSIMs, Handy-Aufladungen',
    'hero_heading_line_two' => '& Rechnungszahlungen, alles an einem Ort',
    'hero_subtitle' => 'Bezahlen Sie bequem mit Mobile Money, Orange Money, Karte, Banküberweisung oder Krypto für alle Ihre digitalen Einkäufe, Abo-Aufladungen und Rechnungen.',
    'hero_cta_gift_cards' => 'Geschenkkarten kaufen',
    'hero_cta_esims' => 'eSIMs entdecken',

    // The feature chips under the hero. :count is the rounded catalogue figure,
    // so the number stays live while the sentence around it is translatable.
    'chip_products_title' => ':count+ digitale Produkte',
    'chip_products_subtitle' => 'Geschenkkarten, eSIMs, Aufladungen und Rechnungszahlungen weltweit',
    'chip_countries_title' => ':count+ Länder abgedeckt',
    'chip_countries_subtitle' => 'eSIMs und lokale Aufladungen in über :count Zielen',
    'chip_reviews_title' => 'Verifizierte Kundenbewertungen',
    'chip_reviews_subtitle' => 'Echtes Feedback von Kunden, die hier gekauft haben',
    'chip_devices_title' => 'Funktioniert auf jedem Gerät',
    'chip_devices_subtitle' => 'Kompatibel mit Laptop, iPad und Handy',
    'chip_search_title' => 'Finden Sie schnell, was Sie brauchen',
    'chip_search_subtitle' => 'Geschenkkarten, eSIMs und Aufladungen in Sekunden finden',

    // Assurance strip under the category cards: three promises and the
    // payment methods that back them.
    'assurance_label' => 'Was Sie erhalten und wie Sie zahlen',
    'assurance_instant_title' => 'Sofortige Lieferung',
    'panel_title' => 'Sofortige Lieferung, kein Aufwand.',
    'assurance_privacy_title' => 'Sicher von Grund auf',
    'assurance_support_title' => 'Persönlicher Support rund um die Uhr',
    'assurance_pay_with' => 'Zahlen Sie mit MOMO, OM, Karten und Krypto',
    'assurance_more_methods' => '+:count weitere',

    // Homepage FAQ. Questions themselves live in the faqs table.
    'faq_heading' => 'Häufig gestellte Fragen',
    'faq_subheading' => 'Weitere Antworten finden Sie auf unserer :link-Seite',
    'faq_link_label' => 'FAQ',

    // Live counts panel. The numbers come from the database; these are
    // only the labels beside them.
    'counts_customers' => 'Registrierte Kunden',
    'counts_orders' => 'Gelieferte Bestellungen',
    'counts_countries' => 'Bediente Länder',
    'counts_rank_value' => 'Vertrauenswürdig',
    'counts_rank_label' => 'Plattform für digitalen Handel',

    // Home rows, the how-it-works steps and the services panel. The
    // offering_* words are joined into sentences with the list glue keys,
    // so a language can set its own separators.
    'esim_row_heading' => 'Bleiben Sie mit eSIMs in über :count Ländern verbunden',
    'esim_row_prev' => 'Vorherige eSIMs',
    'esim_row_next' => 'Weitere eSIMs',
    'teaser_flights_and_stays' => 'Flüge und Unterkünfte',
    'teaser_flights_body' => 'Reisen Sie klüger mit sicheren Buchungen, schnellen Bestätigungen und weltweitem Zugang von überall.',
    'teaser_stays_body' => 'Verwandeln Sie digitales Geld in echte Reisen, Komfort und unvergessliche Erlebnisse.',
    'row_featured_gift_cards' => 'Ausgewählte Geschenkkarten',
    'row_popular_gift_cards' => 'Beliebte Geschenkkarten',
    'row_instant_topups' => 'Sofortige Handy-Aufladungen',
    'alt_gift_card' => ':name Geschenkkarte',
    'alt_top_up' => ':name Aufladung',
    'how_heading' => 'So funktioniert es',
    'how_step1_title' => '1. Produkt oder Service auswählen',
    'how_step1_body' => 'Wählen Sie aus über :count :offerings, die bereitstehen, um Ihren Einkauf zu vereinfachen.',
    'how_step2_title' => '2. Bezahlen Sie mit Karte, Krypto, Mobile Money, Banküberweisung usw.',
    'how_step2_body' => 'Greifen Sie auf über :count digitale Produkte zu, darunter :offerings und mehr, alles auf einer nahtlosen Plattform.',
    'how_step3_title' => '3. Sofort erhalten',
    'how_step3_body' => 'Ihr Produkt kommt in Sekunden an, einsatzbereit, per E-Mail und in Ihrem Kundenbereich, wenn Sie bei uns registriert sind.',
    'offering_gift_cards' => 'Geschenkkarten',
    'offering_esims' => 'eSIMs',
    'offering_flights' => 'Flüge',
    'offering_stays' => 'Unterkünfte',
    'offering_global_stays' => 'Unterkünfte weltweit',
    'offering_topups' => 'Handy-Aufladungen',
    'offering_bills' => 'Rechnungszahlungen',
    'list_glue' => ', ',
    'list_last_glue' => ' und ',
    'list_last_glue_serial' => ' und ',
    'promo_heading' => 'Was wir bieten',
    'promo_aria' => 'Unsere Leistungen',
    'promo_title' => 'Was Rshop Ihnen bietet',
    'promo_services_count' => 'über 23 digitale Services',
    'promo_access' => 'Nutzen Sie :services in einem starken Ökosystem, darunter :offerings.',
    'promo_together' => ':site vereint alles unter einem Dach und macht internationales Einkaufen, Konnektivität, Reisen und alltägliche digitale Zahlungen schnell, reibungslos und stressfrei.',
    'promo_tagline_one' => 'Eine Plattform. Unendliche Möglichkeiten.',
    'promo_tagline_two' => 'Gemacht, um Ihr Leben einfacher zu machen.',
    'promo_copy_manually' => 'Kopieren Sie diesen Empfehlungslink manuell:',
    'promo_copy_link_aria' => 'Empfehlungslink kopieren',
    'promo_share_link' => 'Teilen Sie Ihren Empfehlungslink und verdienen Sie RCoin',
    'promo_set_code' => 'Legen Sie Ihren Empfehlungscode fest, um zu verdienen',
    'promo_open_rewards' => 'Prämien öffnen',
    'promo_guest_title' => 'Richten Sie Ihr Konto ein, um RCoin zu verdienen',
    'promo_guest_cta' => 'Kostenloses Konto erstellen',
];
