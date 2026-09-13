<?php

/**
 * Email and in-app notification copy.
 *
 * Rendered in the RECIPIENT language. User::preferredLocale() is what carries
 * that into a queued job, rather than the locale of whoever triggered it.
 */
return [
    'hello' => 'Gentile :name,',
    'regards' => 'Grazie,',
    'order_confirmed_subject' => 'Il suo ordine è confermato',
    'order_confirmed_body' => 'Abbiamo ricevuto il suo pagamento e il suo ordine è in preparazione.',
    'order_delivered_subject' => 'Il suo ordine è pronto',
    'verify_email_subject' => 'Confermi il suo indirizzo e-mail',
    'verify_email_action' => 'Conferma e-mail',
    'reset_password_subject' => 'Reimposti la sua password',
    'reset_password_action' => 'Reimposta password',
    'refund_issued_subject' => 'Il suo rimborso è stato effettuato',
    'no_reply' => 'Questo è un messaggio automatico, la preghiamo di non rispondere.',
];
