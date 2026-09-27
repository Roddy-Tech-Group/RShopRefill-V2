<?php

/**
 * Sign-in, registration and account security screens.
 *
 * The framework auth keys (failed / password / throttle) live here too, under
 * the names Laravel looks up. Putting them anywhere else means a failed login
 * falls back to English whatever language the customer chose.
 */
return [
    'failed' => 'Queste credenziali non corrispondono ai nostri dati.',
    'password' => 'La password inserita non è corretta.',
    'throttle' => 'Troppi tentativi di accesso. Riprovi tra :seconds secondi.',
    'sign_in' => 'Accedi',
    'sign_up' => 'Registrati',
    'create_account' => 'Crea account',
    'email' => 'Indirizzo e-mail',
    'password_label' => 'Password',
    'confirm_password' => 'Conferma password',
    'full_name' => 'Nome completo',
    'first_name' => 'Nome',
    'last_name' => 'Cognome',
    'referral_code' => 'Codice invito',
    'forgot_password' => 'Password dimenticata?',
    'remember_me' => 'Ricordami',
    'continue_with_google' => 'Continua con Google',
    'or' => 'oppure',
    'agree_to_terms' => 'Accetto :terms e :privacy.',
    'agree_required' => 'Per continuare accetti i Termini di servizio e l\'Informativa sulla privacy.',
    'privacy_note' => 'I suoi dati sono protetti e trattati secondo la nostra :privacy',
    'password_requirements' => 'Almeno 8 caratteri, di cui un numero.',
    'password_min_length' => 'Almeno 8 caratteri',
    'password_has_number' => 'Contiene un numero',
    'brand_tagline' => 'Il suo mondo digitale, tutto in un unico posto.',
    'brand_subtitle' => 'Carte regalo, eSIM, ricariche mobili e pagamento bollette.',

    // The sign-in / register modal (livewire/auth/auth-modal). :site is the
    // site name and :email the address just registered - neither is translated.
    'modal_log_in' => 'Accedi',
    'modal_register' => 'Registrati',
    'modal_welcome_aboard' => 'Benvenuto a bordo',
    'modal_join' => 'Unisciti a :site e inizia subito a fare acquisti',
    'modal_welcome_back' => 'Bentornato',
    'modal_sign_in_to' => 'Accedi al tuo account :site',
    'modal_email' => 'Email',
    'modal_show_password' => 'Mostra password',
    'modal_hide_password' => 'Nascondi password',
    'modal_signing_in' => 'Accesso in corso...',
    'modal_no_account' => 'Non hai un account?',
    'modal_create_one' => 'Creane uno',
    'modal_step_of' => 'Passo :step di :total',
    'modal_step_details' => 'I tuoi dati',
    'modal_step_security' => 'Sicurezza',
    'modal_step_finish' => 'Conclusione',
    'modal_full_name' => 'Nome completo',
    'modal_name_placeholder' => 'Il tuo nome',
    'modal_phone' => 'Numero di telefono',
    'modal_dial_code' => 'Seleziona il prefisso internazionale',
    'modal_search_country' => 'Cerca paese o prefisso',
    'modal_no_match' => 'Nessun risultato',
    'modal_password_hint' => 'Più di 8 caratteri con maiuscole, minuscole, numeri e almeno un carattere speciale',
    'modal_confirm_password' => 'Conferma password',
    'modal_gender' => 'Genere',
    'modal_gender_male' => 'Uomo',
    'modal_gender_female' => 'Donna',
    'modal_gender_other' => 'Altro',
    'modal_optional' => '(facoltativo)',
    'modal_referral_placeholder' => 'Inserisci il codice invito di un amico',
    'modal_terms_consent' => '* Dichiaro di aver letto e di accettare i :terms e :privacy',
    'modal_terms_link' => 'termini di utilizzo',
    'modal_privacy_link' => 'l’informativa sulla privacy',
    'modal_newsletter' => 'Non perderti il nostro prossimo lancio di coupon! Iscrivimi per ricevere sconti esclusivi e codici promozionali',
    'modal_create_account' => 'Crea account',
    'modal_creating_account' => 'Creazione dell’account...',
    'modal_have_account' => 'Hai già un account?',
    'modal_account_created' => 'Account creato',
    'modal_verification_sent' => 'Abbiamo inviato un link di verifica a :email. Verifica la tua email per iniziare a ordinare. Nel frattempo puoi navigare sul sito.',
    'modal_no_email' => 'Non hai ricevuto l’email?',
    'modal_resend' => 'Invialo di nuovo',
    'modal_sending' => 'Invio in corso...',
    'modal_resent' => 'Un nuovo link di verifica è in arrivo.',
    'modal_resend_wait' => 'Attendi un momento prima di richiedere un altro link.',
    'modal_continue_account' => 'Vai al mio account',

    // Errors the modal's server side raises.
    'registrations_closed' => 'Le nuove registrazioni sono temporaneamente chiuse.',
    'phone_taken' => 'Questo numero di telefono è già registrato. Prova ad accedere.',
    'security_unavailable' => 'Il controllo di sicurezza è temporaneamente non disponibile. Riprova.',
    'security_failed' => 'Verifica di sicurezza non riuscita. Aggiorna la pagina e riprova.',

    // Field names for the modal's validation messages, so an error names the
    // field rather than the Livewire property behind it.
    'modal_attributes' => [
        'name' => 'nome',
        'email' => 'email',
        'country' => 'paese',
        'phone' => 'numero di telefono',
        'password' => 'password',
        'password_confirmation' => 'conferma password',
        'gender' => 'genere',
        'terms' => 'termini',
        'referral' => 'codice invito',
    ],
];
