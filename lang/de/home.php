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
    'chip_countries_subtitle' => 'Reise-eSIMs und lokale Aufladungen in über :count Zielen',
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
];
