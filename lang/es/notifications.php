<?php

/**
 * Email and in-app notification copy.
 *
 * Rendered in the RECIPIENT language. User::preferredLocale() is what carries
 * that into a queued job, rather than the locale of whoever triggered it.
 */
return [
    'hello' => 'Hola :name,',
    'regards' => 'Gracias,',
    'order_confirmed_subject' => 'Tu pedido está confirmado',
    'order_confirmed_body' => 'Recibimos tu pago y tu pedido está en camino.',
    'order_delivered_subject' => 'Tu pedido está listo',
    'verify_email_subject' => 'Confirma tu correo electrónico',
    'verify_email_action' => 'Confirmar correo',
    'reset_password_subject' => 'Restablece tu contraseña',
    'reset_password_action' => 'Restablecer contraseña',
    'refund_issued_subject' => 'Tu reembolso ya fue emitido',
    'no_reply' => 'Este es un mensaje automático, por favor no respondas.',
];
