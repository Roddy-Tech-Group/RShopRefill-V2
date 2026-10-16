<?php

/**
 * Sign-in, registration and account security screens.
 *
 * The framework auth keys (failed / password / throttle) live here too, under
 * the names Laravel looks up. Putting them anywhere else means a failed login
 * falls back to English whatever language the customer chose.
 */
return [
    'failed' => 'Wannan bayanin bai dace da rajistarmu ba.',
    'password' => 'Kalmar sirrin da aka shigar ba daidai ba ce.',
    'throttle' => 'An yi ƙoƙarin shiga da yawa. Ka sake gwadawa cikin daƙiƙa :seconds.',
    'sign_in' => 'Shiga',
    'sign_up' => 'Yi rajista',
    'create_account' => 'Buɗe asusu',
    'email' => 'Adireshin imel',
    'password_label' => 'Kalmar sirri',
    'confirm_password' => 'Tabbatar da kalmar sirri',
    'full_name' => 'Cikakken suna',
    'first_name' => 'Sunan farko',
    'last_name' => 'Sunan mahaifi',
    'referral_code' => 'Kodin gayyata',
    'forgot_password' => 'Ka manta kalmar sirri?',
    'remember_me' => 'Ka tuna da ni',
    'continue_with_google' => 'Ci gaba da Google',
    'or' => 'ko',
    'agree_to_terms' => 'Na tabbatar cewa shekaruna 18 ne ko sama da haka kuma na yarda da :terms da :privacy.',
    'agree_required' => 'Da fatan za a tabbatar cewa shekarunka 18 ne ko sama da haka kuma ka yarda da Sharuɗɗan Hidima da Manufar Sirri don ci gaba.',
    'privacy_note' => 'An kare bayananka kuma ana sarrafa su bisa :privacy',
    'password_requirements' => 'Aƙalla haruffa 8, tare da lamba ɗaya.',
    'password_min_length' => 'Aƙalla haruffa 8',
    'password_has_number' => 'Ya ƙunshi lamba',
    'brand_tagline' => 'Duniyarka ta dijital, duk a wuri ɗaya.',
    'brand_subtitle' => 'Katunan kyauta, eSIM, cajin waya da biyan bili.',

    // The sign-in / register modal (livewire/auth/auth-modal). :site is the
    // site name and :email the address just registered - neither is translated.
    'modal_log_in' => 'Shiga',
    'modal_register' => 'Yi rajista',
    'modal_welcome_aboard' => 'Barka da zuwa',
    'modal_join' => 'Shiga :site ka fara siyayya nan take',
    'modal_welcome_back' => 'Barka da dawowa',
    'modal_sign_in_to' => 'Shiga asusunka na :site',
    'modal_email' => 'Imel',
    'modal_show_password' => 'Nuna kalmar sirri',
    'modal_hide_password' => 'Ɓoye kalmar sirri',
    'modal_signing_in' => 'Ana shiga...',
    'modal_no_account' => 'Ba ka da asusu?',
    'modal_create_one' => 'Buɗe ɗaya',
    'modal_step_of' => 'Mataki :step cikin :total',
    'modal_step_details' => 'Bayananka',
    'modal_step_security' => 'Tsaro',
    'modal_step_finish' => 'Kammalawa',
    'modal_full_name' => 'Cikakken suna',
    'modal_name_placeholder' => 'Sunanka',
    'modal_phone' => 'Lambar waya',
    'modal_dial_code' => 'Zaɓi lambar kiran ƙasa',
    'modal_search_country' => 'Nemi ƙasa ko lamba',
    'modal_no_match' => 'Babu wanda ya dace',
    'modal_password_hint' => 'Fiye da haruffa 8 masu manyan haruffa, ƙananan haruffa, lambobi da aƙalla alama ta musamman ɗaya',
    'modal_confirm_password' => 'Tabbatar da kalmar sirri',
    'modal_gender' => 'Jinsi',
    'modal_gender_male' => 'Namiji',
    'modal_gender_female' => 'Mace',
    'modal_gender_other' => 'Wani',
    'modal_optional' => '(ba dole ba)',
    'modal_referral_placeholder' => 'Shigar da lambar gayyata ta aboki',
    'modal_terms_consent' => '* Na tabbatar cewa shekaruna 18 ne ko sama da haka kuma na karanta kuma na yarda da :terms da :privacy',
    'modal_terms_link' => 'sharuɗɗan amfani',
    'modal_privacy_link' => 'sanarwar sirri',
    'modal_newsletter' => 'Kada ka rasa kuponmu na gaba! Yi mini rajista don samun rangwame na musamman da lambobin talla',
    'modal_create_account' => 'Buɗe asusu',
    'modal_creating_account' => 'Ana buɗe asusu...',
    'modal_have_account' => 'Kana da asusu tuni?',
    'modal_account_created' => 'An buɗe asusu',
    'modal_verification_sent' => 'Mun aika hanyar tabbatarwa zuwa :email. Tabbatar da imel ɗinka don fara yin oda. Kafin nan, za ka iya ci gaba da dubawa.',
    'modal_no_email' => 'Ba ka sami imel ɗin ba?',
    'modal_resend' => 'Sake aikawa',
    'modal_sending' => 'Ana aikawa...',
    'modal_resent' => 'Sabuwar hanyar tabbatarwa tana kan hanya.',
    'modal_resend_wait' => 'Da fatan ka jira ɗan lokaci kafin ka nemi wata hanya.',
    'modal_continue_account' => 'Ci gaba zuwa asusuna',

    // Errors the modal's server side raises.
    'registrations_closed' => 'An rufe sabbin rajista na ɗan lokaci.',
    'phone_taken' => 'An riga an yi rajistar wannan lambar waya. Gwada shiga maimakon haka.',
    'security_unavailable' => 'Binciken tsaro ba ya samuwa a yanzu. Da fatan a sake gwadawa.',
    'security_failed' => 'Tabbatar da tsaro ya kasa. Sabunta shafin sannan a sake gwadawa.',

    // Field names for the modal's validation messages, so an error names the
    // field rather than the Livewire property behind it.
    'modal_attributes' => [
        'name' => 'suna',
        'email' => 'imel',
        'country' => 'ƙasa',
        'phone' => 'lambar waya',
        'password' => 'kalmar sirri',
        'password_confirmation' => 'tabbatar da kalmar sirri',
        'gender' => 'jinsi',
        'terms' => 'sharuɗɗa',
        'referral' => 'lambar gayyata',
    ],
];
