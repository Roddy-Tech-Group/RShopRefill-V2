<?php

/**
 * Die Texte des eSIM-Shops. Warum sie in einer eigenen Datei stehen, steht in
 * lang/en/esim.php. Länder-, Regions- und Tarifnamen, Preise und Marken
 * bleiben unverändert und kommen über :placeholders.
 */
return [
    // Seitentitel.
    'page_title' => ':region eSIM',
    'heading_region' => 'eSIMs für :region',
    'breadcrumb_store' => 'eSIM-Shop',

    // Werbeflächen der eSIM-Seite.
    'hero_1_heading' => 'Genießen Sie die Freiheit unbegrenzter Daten',
    'hero_1_text' => 'Schauen Sie das Video, hören Sie den Song, laden Sie die App herunter. Entdecken Sie unsere eSIM-Datenpakete für eine unterbrechungsfreie Verbindung in über 190 Ländern.',
    'hero_2_heading' => 'Verbunden, sobald Sie landen',
    'hero_2_text' => 'Vom Flughafen-Gate bis in den Besprechungsraum: Reisende, Freelancer und Remote-Teams haben sofort Daten in über 190 Ländern. Keine Roaming-Rechnungen, kein SIM-Wechsel.',
    'hero_3_heading' => 'In Minuten eingerichtet, noch bevor Sie packen',
    'hero_3_text' => 'Kaufen Sie Ihren Datentarif, scannen Sie den QR-Code und los geht es. Keine Warteschlangen im Laden, keine Plastik-SIM, die verloren gehen kann, und Ihre gewohnte Nummer funktioniert weiter für Anrufe und Nachrichten.',
    'go_to_advert' => 'Zu Anzeige :number',

    // Tabs und Überschriften des Rasters.
    'tab_popular' => 'Beliebt',
    'tab_local' => 'Lokal',
    'tab_regional' => 'Regional',
    'tab_global' => 'Weltweit',
    'tab_unlimited' => 'Unbegrenzt',
    'title_popular' => 'Beliebte Reiseziele',
    'title_local' => 'Lokale eSIMs',
    'title_regional' => 'Regionale eSIMs',
    'title_global' => 'Weltweite eSIMs',
    'title_unlimited' => 'eSIMs mit unbegrenzten Daten',
    'title_all' => 'Alle eSIMs',
    'subtitle_popular' => 'Entdecken Sie unsere beliebtesten eSIMs. Pakete ab dem angezeigten Preis.',
    'subtitle_local' => 'eSIMs für ein einzelnes Land. Pakete ab dem angezeigten Preis.',
    'subtitle_regional' => 'Regionale eSIMs für mehrere Länder. Pakete ab dem angezeigten Preis.',
    'subtitle_global' => 'eSIMs mit weltweiter Abdeckung. Pakete ab dem angezeigten Preis.',
    'subtitle_unlimited' => 'Reiseziele mit einem Tarif für unbegrenzte Daten. Pakete ab dem angezeigten Preis.',
    'subtitle_all' => 'Alle eSIMs in unserem Angebot. Pakete ab dem angezeigten Preis.',

    // Suche.
    'search_placeholder' => 'Wohin geht die Reise?',
    'search_label' => 'eSIM-Reiseziele durchsuchen',
    'clear_search' => 'Suche löschen',
    'show_matching' => 'Passende Reiseziele anzeigen',

    // Verfügbarkeit und leere Zustände.
    'unavailable_title' => 'eSIMs sind vorübergehend nicht verfügbar',
    'unavailable_browse' => 'Sie können die Abdeckung ansehen, aber Käufe sind gerade pausiert. Schauen Sie bald wieder vorbei.',
    'unavailable_view' => 'Sie können die Tarife ansehen, aber Käufe sind gerade pausiert. Schauen Sie bald wieder vorbei.',
    'empty_title' => 'Noch keine eSIMs verfügbar',
    'empty_body' => 'Wir erweitern gerade die Abdeckung. Schauen Sie bald wieder vorbei.',
    'browse_all' => 'Alle :count Reiseziele ansehen',

    // Chips der Reiseziel-Karten.
    'from' => 'ab',
    'from_data' => 'ab :data',
    'days_range' => ':range Tage',
    'unlimited' => 'Unbegrenzt',
    'chip_data' => 'Daten',
    'chip_phone_number' => 'Telefonnummer',
    'chip_data_only' => 'Nur Daten',
    'chip_top_up' => 'Aufladbar',
    'view_plans' => 'Tarife ansehen',

    // Prüfung vor dem Kauf.
    'tip_local_heading' => 'Kaufen Sie diese eSIM nur, wenn Sie in :name sind oder dorthin reisen',
    'tip_regional_heading' => 'Prüfen Sie, ob :name das Land abdeckt, in das Sie reisen',
    'tip_local_body' => 'Sie verbindet sich mit den Netzen dort und funktioniert nirgendwo sonst.',
    'tip_regional_body' => 'Sie funktioniert in den Ländern, die dieser Tarif auflistet, und nirgendwo sonst. Die vollständige Liste steht auf der Seite des Tarifs.',
    'tip_continue' => 'Weiter zu :name',
    'tip_not_going' => 'Sie reisen nicht dorthin? Eine eSIM deckt Sie weltweit ab.',
    'tip_buy_global' => ':name kaufen',

    // Reiseziel-Karte auf der Startseite.
    'card_label_global' => 'WELTWEITE eSIM',
    'card_label_regional' => 'REGIONALE eSIM',
    'card_label_local' => 'LOKALE eSIM',
    'card_network_available' => ':network verfügbar',
    'card_aria_from' => ':name, ab :price',
    'card_from_price' => 'Ab :price',
    'card_instant_qr' => 'Sofort-QR',
    'card_hotspot' => 'Hotspot',
    'card_unavailable' => 'Nicht verfügbar',
    'card_currently_unavailable' => 'Derzeit nicht verfügbar',
    'card_global_description' => 'US-Daten + echte US-Nummer. Hält Sie überall verbunden.',
    'card_region_description' => 'Bleiben Sie in :count Ländern verbunden.',
    'card_region_description_generic' => 'Bleiben Sie in der ganzen Region verbunden.',
    'card_local_description' => 'Zuverlässige mobile Daten für Ihre Reise nach :country.',
    'card_local_description_article' => 'Zuverlässige mobile Daten für Ihre Reise nach :country.',

    // Kopf des Reiseziels.
    'countries_and_networks_count' => ':count Länder und Netze',
    'networks' => 'Netze',
    'check_compatibility' => 'Kompatibilität prüfen',
    'topup_note' => 'Wenn es knapp wird, können Sie jederzeit :topup',
    'topup_word' => 'aufladen',
    'starts_on_connect' => 'Das Paket startet, sobald Sie sich mit einem unterstützten Netz verbinden',
    'guard_warning' => 'Kaufen Sie diese eSIM nur, wenn Sie in :region sind oder dorthin reisen - sie funktioniert nur in den Netzen von :local. Sie reisen nicht dorthin? :link für weltweite Abdeckung.',
    'guard_link' => 'Kaufen Sie stattdessen Discover Global',
    'change_region' => 'Land oder Region wechseln',
    'search_region' => 'Land oder Region suchen',
    'choose_region' => 'Land oder Region wählen',
    'instant_activation' => 'Sofortige Aktivierung',
    'no_id_required' => 'Kein Ausweis nötig',
    'covers_countries' => 'Deckt :count Länder und Gebiete ab',

    // Tarifauswahl und Tarifkarten.
    'tab_voice' => 'Daten / Anrufe / SMS',
    'tab_data' => 'Nur Daten',
    'mode_fixed' => 'Festes Datenvolumen',
    'choose_package' => 'Wählen Sie Ihr Paket',
    'badge_calls' => 'Anrufe',
    'day_one' => ':count Tag',
    'days_other' => ':count Tage',
    'plan_minutes' => ':count Min.',
    'plan_sms' => ':count SMS',
    'get_esim' => 'eSIM holen',
    'no_packages' => 'Derzeit keine Pakete in dieser Kategorie.',

    // In jedem Sprachtarif enthalten.
    'voice_includes' => 'Jeder Sprachtarif enthält',
    'voice_app_title' => 'App-Verifizierung',
    'voice_app_desc' => 'WhatsApp, Telegram, TikTok und viele weitere Apps, die eine Nummer brauchen.',
    'voice_imessage_title' => 'Unbegrenztes iMessage',
    'voice_imessage_desc' => 'Unbegrenztes iMessage für die gesamte Laufzeit Ihres Tarifs.',
    'voice_topup_title' => 'Verlängerbar und aufladbar',
    'voice_topup_desc' => 'Jederzeit für lokale Anrufe aufladen, mit unbegrenztem iMessage.',

    // Breitere Abdeckung.
    'broader_title' => 'Mehr Abdeckung nötig?',
    'broader_subtitle' => 'Entdecken Sie unsere regionalen und weltweiten eSIMs. Pakete ab dem angezeigten Preis, mit Abdeckung für das gewählte Reiseziel.',

    // Warum wir.
    'why_heading' => 'Warum Reisende eSIMs von :site wählen',
    'why_coverage' => 'Lokale, regionale und weltweite Abdeckung für :count Reiseziele',
    'why_flexible' => 'Flexible Pakete, auch mit unbegrenzten Daten',
    'why_pay_methods' => 'Zahlen Sie mit Mobile Money, Karte, Krypto, Apple Pay oder Banküberweisung',
    'why_install' => 'Einfach installieren und einrichten, in Minuten verbunden',

    // So fügen Sie Ihre eSIM hinzu.
    'how_heading' => 'So fügen Sie Ihre eSIM hinzu',
    'how_subtitle' => 'Drei schnelle Schritte und Sie surfen zu lokalen Tarifen. Kein SIM-Fach, keine Schlange am Flughafenkiosk.',
    'how_step_1_title' => 'Öffnen Sie Einstellungen &rarr; Mobilfunk',
    'how_step_1_body' => 'Entsperren Sie Ihr Telefon und tippen Sie auf Mobilfunk (bei älteren iPhones Mobiles Netz). Hier liegen alle Leitungen Ihres Geräts.',
    'how_step_2_title' => 'Tippen Sie auf eSIM hinzufügen und scannen Sie den QR-Code',
    'how_step_2_body' => 'Wir senden Ihnen den QR-Code Sekunden nach dem Bezahlen per E-Mail. Richten Sie die Kamera darauf oder tippen Sie auf Details manuell eingeben, um den Code einzufügen.',
    'how_step_3_title' => 'Einschalten und Datenroaming aktivieren',
    'how_step_3_body' => 'Aktivieren Sie Diese Leitung aktivieren und dann Datenroaming für die neue Leitung. Sobald Sie landen, verbindet sich Ihr Telefon zu lokalen Tarifen.',
    'how_guide' => 'Kommen Sie nicht weiter? Lesen Sie die vollständige Anleitung',

    // Anonymer Kauf.
    'anon_heading' => 'Anonyme eSIM mit MOMO, Karten, Krypto und mehr. Kein KYC nötig.',
    'anon_body' => 'Anders als klassische SIM-Karten, für die Sie Reisepass und persönliche Daten angeben müssen, können Sie Ihre eSIM von :site mit Mobile Money, Karte, Krypto und mehr kaufen. Keine Identitätsprüfung, keine Erhebung persönlicher Daten.',
    'anon_kyc_title' => 'Kein KYC nötig',
    'anon_kyc_body' => 'Sofort kaufen, ohne Ausweisprüfung oder persönliche Angaben.',
    'anon_pay_title' => 'Zahlen Sie, wie Sie wollen',
    'anon_pay_body' => 'Mobile Money, Karten, Apple Pay, Banküberweisung oder Krypto (Bitcoin, Ethereum, Tether, BNB, Solana, Litecoin). Sie entscheiden.',
    'anon_delivery_title' => 'Sofortige Lieferung',
    'anon_delivery_body' => 'Sie erhalten den QR-Code Ihrer eSIM in Ihrem Posteingang, sobald die Zahlung bestätigt ist.',

    // Zahlungsmethoden.
    'payments_heading' => 'Akzeptierte Zahlungsmethoden',
    'payments_subtitle' => 'Zahlen Sie mit Krypto oder Ihrer lokalen Karte, in der Währung, die Sie bereits haben.',
    'payments_label' => 'Akzeptierte Zahlungsmethoden',

    // Keine Tarife.
    'no_plans_title' => 'Keine Datentarife verfügbar',
    'no_plans_body' => 'Für diese Region sind gerade keine Tarife vorrätig. Schauen Sie später wieder vorbei.',
    'browse_other_regions' => 'Andere Regionen ansehen',

    // Kaufdialog.
    'calls_and_texts' => 'Anrufe & SMS',
    'coverage' => 'Abdeckung',
    'buy_works_across' => 'Funktioniert in :countries und verbindet sich in jedem automatisch mit einem lokalen Netz.',
    'countries_count' => ':count Ländern',
    'more_count' => '+:count weitere',
    'buy_works_in' => 'Funktioniert in :region. Sie verbindet sich bei der Ankunft, und Sie können sie schon vor der Reise installieren.',
    'pay_in' => 'Zahlen in',
    'total' => 'Gesamt',
    'package_details' => 'Paketdetails',

    // Netzdialog.
    'countries_networks' => 'Länder & Netze',
    'networks_across' => 'Diese eSIM funktioniert in :count Ländern und verbindet sich in jedem automatisch mit einem lokalen Netz.',
    'search_country' => 'Nach Land suchen',
    'networks_switch' => 'Diese eSIM kann je nach Verfügbarkeit und Geschwindigkeit zwischen den folgenden Netzen wechseln. Sie können das in den Geräteeinstellungen anpassen.',
    'networks_auto' => 'Ihr Gerät verbindet sich automatisch mit dem besten verfügbaren lokalen Netz in :region.',

    // Kompatibilität.
    'compat_intro' => 'Um eine eSIM zu nutzen, muss ein Gerät alle drei Bedingungen erfüllen:',
    'compat_supports' => 'Das Gerät unterstützt eSIMs.',
    'compat_unlocked' => 'Das Gerät hat keine Netz- oder Anbietersperre.',
    'compat_not_jailbroken' => 'Das Gerät ist nicht gejailbreakt (iOS) oder gerootet (Android).',
    'compat_missing_title' => 'Ihr Gerät ist nicht dabei?',
    'compat_missing_body' => 'Die Liste wird aktuell gehalten, ist aber nicht vollständig, und manche regionalen Modelle eines gelisteten Geräts haben keine eSIM. Fragen Sie beim Hersteller nach, wenn Ihres fehlt.',
    'compat_find' => 'Finden Sie Ihr Gerät',
    'compat_counts' => ':models Modelle von :brands Herstellern',
    'compat_search' => 'Nach Hersteller oder Modell suchen',
    'compat_keep_typing' => 'Tippen Sie weiter, mindestens drei Zeichen.',
    'compat_models' => ':count Modell|:count Modelle',
    'compat_no_match' => 'Kein Hersteller oder Modell passt zu dieser Suche.',

    // Paketdetails.
    'details_package' => 'Paket',
    'details_texts' => 'SMS',
    'details_validity' => 'Gültigkeit',
    'details_flexible' => 'Flexibel',
    'details_apple_services' => 'iMessage & Apple-Dienste',
    'details_imessage_desc' => 'Unbegrenzt Nachrichten an andere Apple-Geräte.',
    'details_imessage_calls' => 'iMessage-Anrufe',
    'details_imessage_calls_desc' => 'Sprachanrufe über iMessage / Daten.',
    'details_imessage_text' => 'iMessage-Nachrichten',
    'details_imessage_text_desc' => 'Nachrichten über iMessage senden und empfangen.',
    'details_facetime_desc' => 'FaceTime-Video und -Audio über Daten.',
    'details_topups_title' => 'Aufladungen & iMessage',
    'details_topups_body' => 'Sie können diesen Tarif jederzeit für lokale Anrufe, SMS und normales Surfen aufladen. iMessage ist immer kostenlos und unbegrenzt - es wird nie von Ihrem Kontingent abgezogen.',
    'details_data_only_body' => 'Das ist ein reiner Datentarif - er hält Sie während der gesamten Laufzeit mit mobilen Daten online. Er hat keine Anrufe, keine SMS und keine Telefonnummer, nur Internetzugang.',
    'details_activation' => 'Aktivierungsregel',
    'activation_first_usage' => 'Die Laufzeit beginnt, wenn sich die eSIM in ihrem Abdeckungsgebiet mit einem unterstützten Netz verbindet - nicht beim Kauf. Installieren Sie sie außerhalb des Abdeckungsgebiets, wird sie bei Ihrer Ankunft aktiviert.',
    'activation_installation' => 'Die Laufzeit beginnt, sobald die eSIM auf Ihrem Gerät installiert ist.',
    'activation_automatic' => 'Die eSIM aktiviert sich automatisch, sobald sie installiert und mit einem unterstützten Netz verbunden ist.',
    'details_topup_option' => 'Aufladeoption',
    'details_rechargeable' => 'Sie können diese eSIM aufladen, wenn Daten oder Laufzeit zu Ende gehen - Aufladungen sind verfügbar, sobald die eSIM installiert ist.',
    'details_not_rechargeable' => 'Dieses Paket ist nicht aufladbar - wenn es aufgebraucht ist, kaufen Sie einen neuen Tarif für dasselbe Gerät.',
    'details_other_info' => 'Weitere Infos',
    'details_after_allowance' => 'Wenn Daten, SMS und Anrufe aufgebraucht sind, können Sie weiterhin über iMessage, WhatsApp und Ihr WLAN telefonieren, schreiben und FaceTime nutzen. Um normale SMS, Anrufe und Daten weiter zu nutzen, laden Sie auf, bevor Ihre eSIM abläuft. Wenn Sie sie weiter nutzen möchten, verlängert eine Aufladung Ihre eSIM automatisch mit dem gewählten Aufladetarif. Auf der Bestellseite der Web App können Sie Ihre eSIM verwalten, verbleibende Daten, Guthaben und SMS sehen, Ihre eSIM aufladen (nur dort) und sie auch dort installieren. Viel Spaß 😊',
    'details_confirm_device' => 'Um Ihre Bestellung abzuschließen, stellen Sie sicher, dass Ihr Gerät eSIM-fähig und entsperrt ist.',
];
