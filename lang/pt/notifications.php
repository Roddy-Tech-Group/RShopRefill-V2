<?php

/**
 * Email and in-app notification copy.
 *
 * Rendered in the RECIPIENT language. User::preferredLocale() is what carries
 * that into a queued job, rather than the locale of whoever triggered it.
 */
return [
    'hello' => 'Olá :name,',
    'regards' => 'Obrigado,',
    'order_confirmed_subject' => 'Seu pedido está confirmado',
    'order_confirmed_body' => 'Recebemos seu pagamento e seu pedido já está a caminho.',
    'order_delivered_subject' => 'Seu pedido está pronto',
    'verify_email_subject' => 'Confirme seu endereço de e-mail',
    'verify_email_action' => 'Confirmar e-mail',
    'reset_password_subject' => 'Redefina sua senha',
    'reset_password_action' => 'Redefinir senha',
    'refund_issued_subject' => 'Seu reembolso foi efetuado',
    'no_reply' => 'Esta é uma mensagem automática, por favor não responda.',
];
