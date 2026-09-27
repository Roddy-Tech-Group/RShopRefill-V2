<?php

/**
 * Sign-in, registration and account security screens.
 *
 * The framework auth keys (failed / password / throttle) live here too, under
 * the names Laravel looks up. Putting them anywhere else means a failed login
 * falls back to English whatever language the customer chose.
 */
return [
    'failed' => 'Estas credenciales no coinciden con nuestros registros.',
    'password' => 'La contraseña ingresada es incorrecta.',
    'throttle' => 'Demasiados intentos de inicio de sesión. Inténtalo de nuevo en :seconds segundos.',
    'sign_in' => 'Iniciar sesión',
    'sign_up' => 'Registrarse',
    'create_account' => 'Crear cuenta',
    'email' => 'Correo electrónico',
    'password_label' => 'Contraseña',
    'confirm_password' => 'Confirmar contraseña',
    'full_name' => 'Nombre completo',
    'first_name' => 'Nombre',
    'last_name' => 'Apellido',
    'referral_code' => 'Código de referido',
    'forgot_password' => '¿Olvidaste tu contraseña?',
    'remember_me' => 'Recordarme',
    'continue_with_google' => 'Continuar con Google',
    'or' => 'o',
    'agree_to_terms' => 'Acepto los :terms y la :privacy.',
    'agree_required' => 'Acepta los Términos del servicio y la Política de privacidad para continuar.',
    'privacy_note' => 'Tu información está protegida y se trata conforme a nuestra :privacy',
    'password_requirements' => 'Mínimo 8 caracteres, incluido un número.',
    'password_min_length' => 'Mínimo 8 caracteres',
    'password_has_number' => 'Incluye un número',
    'brand_tagline' => 'Tu mundo digital, todo en un solo lugar.',
    'brand_subtitle' => 'Tarjetas de regalo, eSIM, recargas móviles y pago de facturas.',

    // The sign-in / register modal (livewire/auth/auth-modal). :site is the
    // site name and :email the address just registered - neither is translated.
    'modal_log_in' => 'Iniciar sesión',
    'modal_register' => 'Registrarse',
    'modal_welcome_aboard' => 'Bienvenido a bordo',
    'modal_join' => 'Únete a :site y empieza a comprar al instante',
    'modal_welcome_back' => 'Hola de nuevo',
    'modal_sign_in_to' => 'Inicia sesión en tu cuenta de :site',
    'modal_email' => 'Correo electrónico',
    'modal_show_password' => 'Mostrar contraseña',
    'modal_hide_password' => 'Ocultar contraseña',
    'modal_signing_in' => 'Iniciando sesión...',
    'modal_no_account' => '¿No tienes una cuenta?',
    'modal_create_one' => 'Crea una',
    'modal_step_of' => 'Paso :step de :total',
    'modal_step_details' => 'Tus datos',
    'modal_step_security' => 'Seguridad',
    'modal_step_finish' => 'Finalizar',
    'modal_full_name' => 'Nombre completo',
    'modal_name_placeholder' => 'Tu nombre',
    'modal_phone' => 'Número de teléfono',
    'modal_dial_code' => 'Seleccionar el prefijo del país',
    'modal_search_country' => 'Buscar país o prefijo',
    'modal_no_match' => 'Sin coincidencias',
    'modal_password_hint' => 'Más de 8 caracteres con mayúsculas, minúsculas, números y al menos un carácter especial',
    'modal_confirm_password' => 'Confirmar contraseña',
    'modal_gender' => 'Género',
    'modal_gender_male' => 'Hombre',
    'modal_gender_female' => 'Mujer',
    'modal_gender_other' => 'Otro',
    'modal_optional' => '(opcional)',
    'modal_referral_placeholder' => 'Introduce el código de referido de un amigo',
    'modal_terms_consent' => '* Declaro que he leído y acepto los :terms y el :privacy',
    'modal_terms_link' => 'términos de uso',
    'modal_privacy_link' => 'aviso de privacidad',
    'modal_newsletter' => '¡No te pierdas nuestro próximo lanzamiento de cupones! Suscríbeme para recibir descuentos exclusivos y códigos promocionales',
    'modal_create_account' => 'Crear cuenta',
    'modal_creating_account' => 'Creando cuenta...',
    'modal_have_account' => '¿Ya tienes una cuenta?',
    'modal_account_created' => 'Cuenta creada',
    'modal_verification_sent' => 'Enviamos un enlace de verificación a :email. Verifica tu correo para empezar a hacer pedidos. Mientras tanto, puedes navegar por la tienda.',
    'modal_no_email' => '¿No recibiste el correo?',
    'modal_resend' => 'Reenviarlo',
    'modal_sending' => 'Enviando...',
    'modal_resent' => 'Un nuevo enlace de verificación va en camino.',
    'modal_resend_wait' => 'Espera un momento antes de solicitar otro enlace.',
    'modal_continue_account' => 'Ir a mi cuenta',

    // Errors the modal's server side raises.
    'registrations_closed' => 'Los nuevos registros están cerrados temporalmente.',
    'phone_taken' => 'Este número de teléfono ya está registrado. Prueba a iniciar sesión.',
    'security_unavailable' => 'La verificación de seguridad no está disponible temporalmente. Inténtalo de nuevo.',
    'security_failed' => 'La verificación de seguridad falló. Actualiza la página e inténtalo de nuevo.',

    // Field names for the modal's validation messages, so an error names the
    // field rather than the Livewire property behind it.
    'modal_attributes' => [
        'name' => 'nombre',
        'email' => 'correo electrónico',
        'country' => 'país',
        'phone' => 'número de teléfono',
        'password' => 'contraseña',
        'password_confirmation' => 'confirmación de contraseña',
        'gender' => 'género',
        'terms' => 'términos',
        'referral' => 'código de referido',
    ],
];
