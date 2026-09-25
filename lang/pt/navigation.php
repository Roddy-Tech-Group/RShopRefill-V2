<?php

/**
 * Nav bars, the category rail and the footer. Category labels live here rather
 * than in products.php because these are the NAVIGATION labels - the catalogue's
 * own category names come from the database.
 */
return [
    'popular_in' => 'Populares em :country',
    'categories' => 'Categorias',
    'featured' => 'Destaques',
    'gift_cards' => 'Cartões-presente',
    'esims' => 'eSIMs',
    'mobile_refill' => 'Recarga de celular',
    'bill_payments' => 'Contas',
    'flights' => 'Voos',
    'stays' => 'Hospedagens',
    'search_placeholder' => 'Busque marcas, categorias, países',
    'sign_in' => 'Entrar',
    'cart' => 'Carrinho',
    'account' => 'Conta',
    'my_orders' => 'Meus pedidos',
    'rcoin_rewards' => 'Recompensas Rcoin',
    'settings' => 'Configurações',
    'limits' => 'Limites',
    'logout' => 'Sair',
    'shop' => 'Loja',
    'company' => 'Empresa',
    'legal' => 'Jurídico',
    'get_the_app' => 'Baixar o app',
    'follow_us' => 'Siga-nos',
    'help_center' => 'Ajuda',
    'faqs' => 'Perguntas frequentes',
    'contact_us' => 'Fale conosco',
    'how_it_works' => 'Como funciona',
    'earn_points' => 'Ganhe pontos',
    'terms_of_service' => 'Termos de Serviço',
    'privacy_policy' => 'Política de Privacidade',
    'cookie_policy' => 'Política de Cookies',
    'compliance' => 'Conformidade',
    'refund_policy' => 'Política de reembolso',
    'accessibility' => 'Acessibilidade',
    'sitemap' => 'Mapa do site',

    // Region suggestion popup under the header's region pill.
    'region_suggest_body' => 'Você está vendo produtos resgatáveis em :country. Ver produtos para:',
    'region_suggest_switch' => 'Trocar',
    'region_suggest_dismiss' => 'Dispensar a sugestão de região',

    // Header category rail and the phone menu's Mobile Refill page.
    'product_categories' => 'Categorias de produtos',
    'topups_all_networks' => 'Todas as operadoras',
    'topups_credit_only' => 'Somente crédito',
    'topups_bundles' => 'Crédito, dados e ligações',

    // Region pill. :language is filled on the server; :country and :currency
    // in the browser, where the live values are.
    'region_pill_label' => 'Região e moeda: :country, :language, :currency. Alterar',

    // Search panel. The type labels say what a result row IS.
    'search_no_results' => 'Nenhum resultado para “:query”',
    'search_group_bill_payments' => 'Pagamento de contas',
    'search_group_suggestions' => 'Sugestões',
    'search_type_gift_card' => 'Cartão-presente',
    'search_type_topup' => 'Recarga de celular',
    'search_type_bill' => 'Pagamento de conta',
    'search_type_esim' => 'eSIM',
    'search_type_page' => 'Página',

    // Account chip and the cart popup. :amount is a formatted price.
    'your_account' => 'Sua conta',
    'cart_decrease' => 'Diminuir',
    'cart_increase' => 'Aumentar',
    'cart_unit_card' => 'Cartão de :amount',
    'cart_unit_esim' => 'eSIM de :amount',
    'cart_unit_topup' => 'Recarga de :amount',
    'cart_unit_bill' => 'Conta de :amount',

    // Referral panel (the nav's Invite a Friend pill and the dashboard top bar).
    'referral_title' => 'Indique amigos e ganhe Rcoin',
    'referral_title_up_to' => 'Indique amigos e ganhe até :amount em Rcoin',
    'referral_body_any_order' => 'Compartilhe seu link. Você ganha Rcoin quando alguém que você indicou conclui um pedido. Só o cadastro não rende nada.',
    'referral_body_first_order' => 'Compartilhe seu link. Você ganha Rcoin quando alguém que você indicou conclui o primeiro pedido. Só o cadastro não rende nada.',
    'referral_see_rewards' => 'Ver suas recompensas Rcoin',
    'referral_sign_in' => 'Entre para obter seu link',
    'referral_new_here' => 'Novo por aqui? :link e seu link fica pronto na hora.',
    'referral_create_account' => 'Crie uma conta',

    // Cookie banner. :site is the site name, :policy the Cookie Policy link.
    'cookie_consent' => 'Consentimento de cookies',
    'cookie_body' => 'Usamos cookies para manter você conectado, proteger seus pagamentos e melhorar o :site. Veja nossa :policy.',
    'cookie_decline' => 'Recusar',
    'cookie_accept' => 'Aceitar cookies',

    // Feedback widget.
    'feedback_region' => 'Feedback sobre o site',
    'feedback_tab' => 'Feedback',
    'feedback_tab_label' => 'Envie seu feedback. Arraste para mover ou use as setas do teclado.',
    'feedback_drag' => 'Arraste para mover',
    'feedback_title' => 'Enviar feedback',
    'feedback_email_placeholder' => 'Seu e-mail',
    'feedback_message_placeholder' => 'Seu feedback...',
    'feedback_send' => 'Enviar',
    'feedback_sending' => 'Enviando...',
    'feedback_thanks' => 'Obrigado pelo feedback',
    'feedback_ticket_opened' => 'Um chamado foi aberto com a equipe.',
    'feedback_send_another' => 'Enviar outro',
    'feedback_security_failed' => 'A verificação de segurança falhou. Tente novamente.',

    // Currency names in the preferences sheet. The codes beside them are not copy.
    'currency_names' => [
        'USD' => 'Dólar americano',
        'EUR' => 'Euro',
        'GBP' => 'Libra esterlina',
        'NGN' => 'Naira nigeriana',
        'XAF' => 'Franco CFA da África Central',
        'ZAR' => 'Rand sul-africano',
        'KES' => 'Xelim queniano',
        'GHS' => 'Cedi ganês',
        'EGP' => 'Libra egípcia',
        'MAD' => 'Dirham marroquino',
        'CAD' => 'Dólar canadense',
        'AUD' => 'Dólar australiano',
        'JPY' => 'Iene japonês',
        'CNY' => 'Yuan chinês',
        'INR' => 'Rupia indiana',
        'BRL' => 'Real brasileiro',
        'AED' => 'Dirham dos Emirados Árabes Unidos',
        'SAR' => 'Riyal saudita',
        'TRY' => 'Lira turca',
        'CHF' => 'Franco suíço',
    ],
];
