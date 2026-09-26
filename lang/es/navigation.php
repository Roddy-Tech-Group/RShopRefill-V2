<?php

/**
 * Nav bars, the category rail and the footer. Category labels live here rather
 * than in products.php because these are the NAVIGATION labels - the catalogue's
 * own category names come from the database.
 */
return [
    'popular_in' => 'Popular en :country',
    'categories' => 'Categorías',
    'featured' => 'Destacados',
    'gift_cards' => 'Tarjetas de regalo',
    'esims' => 'eSIM',
    'mobile_refill' => 'Recarga móvil',
    'bill_payments' => 'Facturas',
    'flights' => 'Vuelos',
    'stays' => 'Alojamientos',
    'search_placeholder' => 'Busca marcas, categorías, países',
    'sign_in' => 'Iniciar sesión',
    'cart' => 'Carrito',
    'account' => 'Cuenta',
    'my_orders' => 'Mis pedidos',
    'rcoin_rewards' => 'Recompensas Rcoin',
    'settings' => 'Configuración',
    'limits' => 'Límites',
    'logout' => 'Cerrar sesión',
    'shop' => 'Tienda',
    'company' => 'Empresa',
    'legal' => 'Legal',
    'get_the_app' => 'Descargar la app',
    'follow_us' => 'Síguenos',
    'help_center' => 'Ayuda',
    'faqs' => 'Preguntas frecuentes',
    'contact_us' => 'Contáctanos',
    'how_it_works' => 'Cómo funciona',
    'earn_points' => 'Gana puntos',
    'terms_of_service' => 'Términos del servicio',
    'privacy_policy' => 'Política de privacidad',
    'cookie_policy' => 'Política de cookies',
    'compliance' => 'Cumplimiento',
    'refund_policy' => 'Política de reembolsos',
    'accessibility' => 'Accesibilidad',
    'sitemap' => 'Mapa del sitio',

    // Region suggestion popup under the header's region pill.
    'region_suggest_body' => 'Estás viendo productos canjeables en :country. Ver productos para:',
    'region_suggest_switch' => 'Cambiar',
    'region_suggest_dismiss' => 'Descartar la sugerencia de región',

    // Header category rail and the phone menu's Mobile Refill page.
    'product_categories' => 'Categorías de productos',

    // Region pill. :language is filled on the server; :country and :currency
    // in the browser, where the live values are.
    'region_pill_label' => 'Región y moneda: :country, :language, :currency. Cambiar',

    // Search panel. The type labels say what a result row IS.
    'search_no_results' => 'No hay resultados para «:query»',
    'search_group_bill_payments' => 'Pago de facturas',
    'search_group_suggestions' => 'Sugerencias',
    'search_type_gift_card' => 'Tarjeta regalo',
    'search_type_topup' => 'Recarga móvil',
    'search_type_bill' => 'Pago de factura',
    'search_type_esim' => 'eSIM',
    'search_type_page' => 'Página',

    // Account chip and the cart popup. :amount is a formatted price.
    'your_account' => 'Tu cuenta',
    'cart_decrease' => 'Disminuir',
    'cart_increase' => 'Aumentar',
    'cart_unit_card' => 'Tarjeta de :amount',
    'cart_unit_esim' => 'eSIM de :amount',
    'cart_unit_topup' => 'Recarga de :amount',
    'cart_unit_bill' => 'Factura de :amount',

    // Referral panel (the nav's Invite a Friend pill and the dashboard top bar).
    'referral_title' => 'Invita a tus amigos y gana Rcoin',
    'referral_title_up_to' => 'Invita a tus amigos y gana hasta :amount en Rcoin',
    'referral_body_any_order' => 'Comparte tu enlace. Ganas Rcoin cuando alguien a quien invitaste completa un pedido. Solo registrarse no genera nada.',
    'referral_body_first_order' => 'Comparte tu enlace. Ganas Rcoin cuando alguien a quien invitaste completa su primer pedido. Solo registrarse no genera nada.',
    'referral_see_rewards' => 'Ver tus recompensas Rcoin',
    'referral_sign_in' => 'Inicia sesión para obtener tu enlace',
    'referral_new_here' => '¿Eres nuevo? :link y tu enlace estará listo al instante.',
    'referral_create_account' => 'Crea una cuenta',

    // Cookie banner. :site is the site name, :policy the Cookie Policy link.
    'cookie_consent' => 'Consentimiento de cookies',
    'cookie_body' => 'Usamos cookies para mantener tu sesión iniciada, proteger tus pagos y mejorar :site. Consulta nuestra :policy.',
    'cookie_decline' => 'Rechazar',
    'cookie_accept' => 'Aceptar cookies',

    // Feedback widget.
    'feedback_region' => 'Comentarios sobre el sitio',
    'feedback_tab' => 'Comentarios',
    'feedback_tab_label' => 'Envíanos tus comentarios. Arrastra para mover o usa las teclas de flecha.',
    'feedback_drag' => 'Arrastra para mover',
    'feedback_title' => 'Enviar comentarios',
    'feedback_email_placeholder' => 'Tu correo electrónico',
    'feedback_message_placeholder' => 'Tus comentarios...',
    'feedback_send' => 'Enviar',
    'feedback_sending' => 'Enviando...',
    'feedback_thanks' => 'Gracias por tus comentarios',
    'feedback_ticket_opened' => 'Se ha abierto un ticket con el equipo.',
    'feedback_send_another' => 'Enviar otro',
    'feedback_security_failed' => 'La verificación de seguridad falló. Inténtalo de nuevo.',

    // Currency names in the preferences sheet. The codes beside them are not copy.
    'currency_names' => [
        'USD' => 'Dólar estadounidense',
        'EUR' => 'Euro',
        'GBP' => 'Libra esterlina',
        'NGN' => 'Naira nigeriana',
        'XAF' => 'Franco CFA de África Central',
        'ZAR' => 'Rand sudafricano',
        'KES' => 'Chelín keniano',
        'GHS' => 'Cedi ghanés',
        'EGP' => 'Libra egipcia',
        'MAD' => 'Dírham marroquí',
        'CAD' => 'Dólar canadiense',
        'AUD' => 'Dólar australiano',
        'JPY' => 'Yen japonés',
        'CNY' => 'Yuan chino',
        'INR' => 'Rupia india',
        'BRL' => 'Real brasileño',
        'AED' => 'Dírham de los EAU',
        'SAR' => 'Riyal saudí',
        'TRY' => 'Lira turca',
        'CHF' => 'Franco suizo',
    ],
];
