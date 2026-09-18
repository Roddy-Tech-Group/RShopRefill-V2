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
    'chip_countries_subtitle' => 'eSIMs de viagem e recargas locais em mais de :count destinos',
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
];
