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
    'hero_heading_line_one' => 'Tarjetas de regalo, eSIM, recargas móviles',
    'hero_heading_line_two' => 'y pago de facturas, todo en un solo lugar',
    'hero_subtitle' => 'Paga fácilmente con Mobile Money, Orange Money, tarjeta, transferencia bancaria o cripto todas tus compras digitales, recargas de suscripciones y pagos de facturas.',
    'hero_cta_gift_cards' => 'Ver tarjetas de regalo',
    'hero_cta_esims' => 'Explorar eSIM',

    // The feature chips under the hero. :count is the rounded catalogue figure,
    // so the number stays live while the sentence around it is translatable.
    'chip_products_title' => ':count+ productos digitales',
    'chip_products_subtitle' => 'Tarjetas de regalo, eSIM, recargas y pago de facturas en todo el mundo',
    'chip_countries_title' => ':count+ países cubiertos',
    'chip_countries_subtitle' => 'eSIMs y recargas locales en más de :count destinos',
    'chip_reviews_title' => 'Opiniones verificadas de clientes',
    'chip_reviews_subtitle' => 'Comentarios reales de clientes que compraron aquí',
    'chip_devices_title' => 'Funciona en cualquier dispositivo',
    'chip_devices_subtitle' => 'Compatible con laptop, iPad y móvil',
    'chip_search_title' => 'Encuentra rápido lo que buscas',
    'chip_search_subtitle' => 'Busca tarjetas de regalo, eSIM y recargas en segundos',

    // Assurance strip under the category cards: three promises and the
    // payment methods that back them.
    'assurance_label' => 'Qué obtienes y cómo pagar',
    'assurance_instant_title' => 'Entrega instantánea',
    'panel_title' => 'Entrega instantánea, sin complicaciones.',
    'assurance_privacy_title' => 'Seguro por diseño',
    'assurance_support_title' => 'Soporte humano 24/7',
    'assurance_pay_with' => 'Paga con MOMO, OM, tarjetas y cripto',
    'assurance_more_methods' => '+:count más',

    // Homepage FAQ. Questions themselves live in the faqs table.
    'faq_heading' => 'Preguntas frecuentes',
    'faq_subheading' => 'Visita nuestra página de :link para más respuestas',
    'faq_link_label' => 'FAQ',

    // Live counts panel. The numbers come from the database; these are
    // only the labels beside them.
    'counts_customers' => 'Clientes registrados',
    'counts_orders' => 'Pedidos entregados',
    'counts_countries' => 'Países atendidos',
    'counts_rank_value' => 'Confiable',
    'counts_rank_label' => 'Plataforma de comercio digital',

    // Home rows, the how-it-works steps and the services panel. The
    // offering_* words are joined into sentences with the list glue keys,
    // so a language can set its own separators.
    'esim_row_heading' => 'Mantente conectado en más de :count países con eSIM',
    'esim_row_prev' => 'eSIM anteriores',
    'esim_row_next' => 'Más eSIM',
    'teaser_flights_and_stays' => 'Vuelos y estancias',
    'teaser_flights_body' => 'Viaja de forma más inteligente con reservas seguras, confirmaciones rápidas y acceso global desde cualquier lugar del mundo.',
    'teaser_stays_body' => 'Convierte tu moneda digital en viajes reales, comodidad y experiencias inolvidables.',
    'row_featured_gift_cards' => 'Tarjetas regalo destacadas',
    'row_popular_gift_cards' => 'Tarjetas regalo populares',
    'row_instant_topups' => 'Recargas móviles al instante',
    'alt_gift_card' => 'Tarjeta regalo :name',
    'alt_top_up' => 'Recarga :name',
    'how_heading' => 'Cómo funciona',
    'how_step1_title' => '1. Elige un producto o servicio',
    'how_step1_body' => 'Elige entre más de :count :offerings listos para simplificar tu experiencia de compra.',
    'how_step2_title' => '2. Paga con tarjeta, cripto, Mobile Money, transferencia bancaria, etc.',
    'how_step2_body' => 'Accede a más de :count productos digitales, como :offerings y más, en una sola plataforma sin complicaciones.',
    'how_step3_title' => '3. Recíbelo al instante',
    'how_step3_body' => 'Tu producto llega en segundos, listo para usar, a tu correo electrónico y a tu panel de cliente si tienes una cuenta con nosotros.',
    'offering_gift_cards' => 'tarjetas regalo',
    'offering_esims' => 'eSIM',
    'offering_flights' => 'vuelos',
    'offering_stays' => 'estancias',
    'offering_global_stays' => 'estancias en todo el mundo',
    'offering_topups' => 'recargas móviles',
    'offering_bills' => 'pago de facturas',
    'list_glue' => ', ',
    'list_last_glue' => ' y ',
    'list_last_glue_serial' => ' y ',
    'promo_heading' => 'Lo que ofrecemos',
    'promo_aria' => 'Nuestros servicios',
    'promo_title' => 'Lo que Rshop tiene para ti',
    'promo_services_count' => 'más de 23 servicios digitales',
    'promo_access' => 'Accede a :services en un potente ecosistema que incluye :offerings.',
    'promo_together' => ':site lo reúne todo en un solo lugar y hace que las compras internacionales, la conectividad, los viajes y los pagos digitales del día a día sean rápidos, fluidos y sin estrés.',
    'promo_tagline_one' => 'Una plataforma. Posibilidades infinitas.',
    'promo_tagline_two' => 'Creada para simplificarte la vida.',
    'promo_copy_manually' => 'Copia este enlace de referido manualmente:',
    'promo_copy_link_aria' => 'Copiar tu enlace de referido',
    'promo_share_link' => 'Comparte tu enlace de referido para ganar RCoin',
    'promo_set_code' => 'Configura tu código de referido para empezar a ganar',
    'promo_open_rewards' => 'Abrir recompensas',
    'promo_guest_title' => 'Crea tu cuenta para empezar a ganar RCoin',
    'promo_guest_cta' => 'Crear una cuenta gratis',
];
