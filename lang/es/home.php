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
    'chip_countries_subtitle' => 'eSIM de viaje y recargas locales en más de :count destinos',
    'chip_reviews_title' => 'Opiniones verificadas de clientes',
    'chip_reviews_subtitle' => 'Comentarios reales de clientes que compraron aquí',
    'chip_devices_title' => 'Funciona en cualquier dispositivo',
    'chip_devices_subtitle' => 'Compatible con laptop, iPad y móvil',
    'chip_search_title' => 'Encuentra rápido lo que buscas',
    'chip_search_subtitle' => 'Busca tarjetas de regalo, eSIM y recargas en segundos',
];
