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
    'hero_heading_line_one' => 'Cartões-presente, eSIMs, recargas de celular',
    'hero_heading_line_two' => '& pagamento de contas, tudo em um só lugar',
    'hero_subtitle' => 'Pague com facilidade por Mobile Money, Orange Money, cartão, transferência bancária ou cripto todas as suas compras digitais, recargas de assinaturas e contas.',
    'hero_cta_gift_cards' => 'Comprar cartões-presente',
    'hero_cta_esims' => 'Conhecer os eSIMs',

    // The feature chips under the hero. :count is the rounded catalogue figure,
    // so the number stays live while the sentence around it is translatable.
    'chip_products_title' => ':count+ produtos digitais',
    'chip_products_subtitle' => 'Cartões-presente, eSIMs, recargas e pagamento de contas no mundo todo',
    'chip_countries_title' => ':count+ países atendidos',
    'chip_countries_subtitle' => 'eSIMs e recargas locais em mais de :count destinos',
    'chip_reviews_title' => 'Avaliações verificadas de clientes',
    'chip_reviews_subtitle' => 'Opiniões reais de clientes que compraram aqui',
    'chip_devices_title' => 'Funciona em todos os dispositivos',
    'chip_devices_subtitle' => 'Compatível com notebook, iPad e celular',
    'chip_search_title' => 'Encontre rápido o que precisa',
    'chip_search_subtitle' => 'Busque cartões-presente, eSIMs e recargas em segundos',

    // Assurance strip under the category cards: three promises and the
    // payment methods that back them.
    'assurance_label' => 'O que você recebe e como pagar',
    'assurance_instant_title' => 'Entrega instantânea',
    'panel_title' => 'Entrega instantânea, sem complicações.',
    'assurance_privacy_title' => 'Seguro por concepção',
    'assurance_support_title' => 'Suporte humano 24 horas',
    'assurance_pay_with' => 'Pague com MOMO, OM, cartões e cripto',
    'assurance_more_methods' => '+:count mais',

    // Homepage FAQ. Questions themselves live in the faqs table.
    'faq_heading' => 'Perguntas frequentes',
    'faq_subheading' => 'Veja nossa página de :link para mais respostas',
    'faq_link_label' => 'FAQ',

    // Live counts panel. The numbers come from the database; these are
    // only the labels beside them.
    'counts_customers' => 'Clientes registados',
    'counts_orders' => 'Pedidos entregues',
    'counts_countries' => 'Países atendidos',
    'counts_rank_value' => 'Confiável',
    'counts_rank_label' => 'Plataforma de comércio digital',

    // Home rows, the how-it-works steps and the services panel. The
    // offering_* words are joined into sentences with the list glue keys,
    // so a language can set its own separators.
    'esim_row_heading' => 'Fique conectado em mais de :count países com eSIMs',
    'esim_row_prev' => 'eSIMs anteriores',
    'esim_row_next' => 'Mais eSIMs',
    'teaser_flights_and_stays' => 'Voos e estadias',
    'teaser_flights_body' => 'Viaje de forma mais inteligente com reservas seguras, confirmações rápidas e acesso global de qualquer lugar do mundo.',
    'teaser_stays_body' => 'Transforme sua moeda digital em viagens reais, conforto e experiências inesquecíveis.',
    'row_featured_gift_cards' => 'Cartões-presente em destaque',
    'row_popular_gift_cards' => 'Cartões-presente populares',
    'row_instant_topups' => 'Recargas de celular instantâneas',
    'alt_gift_card' => 'Cartão-presente :name',
    'alt_top_up' => 'Recarga :name',
    'how_heading' => 'Como funciona',
    'how_step1_title' => '1. Escolha um produto ou serviço',
    'how_step1_body' => 'Escolha entre mais de :count :offerings prontos para simplificar sua experiência de compra.',
    'how_step2_title' => '2. Pague com cartão, cripto, Mobile Money, transferência bancária etc.',
    'how_step2_body' => 'Acesse mais de :count produtos digitais, incluindo :offerings e muito mais, em uma única plataforma prática.',
    'how_step3_title' => '3. Receba na hora',
    'how_step3_body' => 'Seu produto chega em segundos, pronto para usar, no seu e-mail e no seu painel de cliente se você tiver uma conta conosco.',
    'offering_gift_cards' => 'cartões-presente',
    'offering_esims' => 'eSIMs',
    'offering_flights' => 'voos',
    'offering_stays' => 'estadias',
    'offering_global_stays' => 'estadias pelo mundo',
    'offering_topups' => 'recargas de celular',
    'offering_bills' => 'pagamento de contas',
    'list_glue' => ', ',
    'list_last_glue' => ' e ',
    'list_last_glue_serial' => ' e ',
    'promo_heading' => 'O que oferecemos',
    'promo_aria' => 'Nossos serviços',
    'promo_title' => 'O que a Rshop tem para você',
    'promo_services_count' => 'mais de 23 serviços digitais',
    'promo_access' => 'Acesse :services em um ecossistema poderoso, incluindo :offerings.',
    'promo_together' => ':site reúne tudo em um só lugar e torna as compras internacionais, a conectividade, as viagens e os pagamentos digitais do dia a dia rápidos, fluidos e sem estresse.',
    'promo_tagline_one' => 'Uma plataforma. Possibilidades infinitas.',
    'promo_tagline_two' => 'Criada para simplificar sua vida.',
    'promo_copy_manually' => 'Copie este link de indicação manualmente:',
    'promo_copy_link_aria' => 'Copiar seu link de indicação',
    'promo_share_link' => 'Compartilhe seu link de indicação para ganhar RCoin',
    'promo_set_code' => 'Defina seu código de indicação para começar a ganhar',
    'promo_open_rewards' => 'Abrir recompensas',
    'promo_guest_title' => 'Crie sua conta para começar a ganhar RCoin',
    'promo_guest_cta' => 'Criar uma conta grátis',
];
