<?php

/**
 * Sign-in, registration and account security screens.
 *
 * The framework auth keys (failed / password / throttle) live here too, under
 * the names Laravel looks up. Putting them anywhere else means a failed login
 * falls back to English whatever language the customer chose.
 */
return [
    'failed' => 'Estas credenciais não correspondem aos nossos registros.',
    'password' => 'A senha informada está incorreta.',
    'throttle' => 'Muitas tentativas de login. Tente novamente em :seconds segundos.',
    'sign_in' => 'Entrar',
    'sign_up' => 'Cadastrar',
    'create_account' => 'Criar conta',
    'email' => 'Endereço de e-mail',
    'password_label' => 'Senha',
    'confirm_password' => 'Confirmar senha',
    'full_name' => 'Nome completo',
    'first_name' => 'Nome',
    'last_name' => 'Sobrenome',
    'referral_code' => 'Código de indicação',
    'forgot_password' => 'Esqueceu a senha?',
    'remember_me' => 'Lembrar de mim',
    'continue_with_google' => 'Continuar com o Google',
    'or' => 'ou',
    'agree_to_terms' => 'Eu concordo com os :terms e a :privacy.',
    'agree_required' => 'Aceite os Termos de Serviço e a Política de Privacidade para continuar.',
    'privacy_note' => 'Suas informações são protegidas e tratadas conforme a nossa :privacy',
    'password_requirements' => 'Pelo menos 8 caracteres, incluindo um número.',
    'password_min_length' => 'Pelo menos 8 caracteres',
    'password_has_number' => 'Inclui um número',
    'brand_tagline' => 'Seu mundo digital, tudo em um só lugar.',
    'brand_subtitle' => 'Cartões-presente, eSIMs, recargas de celular e pagamento de contas.',

    // The sign-in / register modal (livewire/auth/auth-modal). :site is the
    // site name and :email the address just registered - neither is translated.
    'modal_log_in' => 'Entrar',
    'modal_register' => 'Cadastrar',
    'modal_welcome_aboard' => 'Bem-vindo a bordo',
    'modal_join' => 'Junte-se ao :site e comece a comprar agora mesmo',
    'modal_welcome_back' => 'Que bom ver você de novo',
    'modal_sign_in_to' => 'Entre na sua conta :site',
    'modal_email' => 'E-mail',
    'modal_show_password' => 'Mostrar senha',
    'modal_hide_password' => 'Ocultar senha',
    'modal_signing_in' => 'Entrando...',
    'modal_no_account' => 'Não tem uma conta?',
    'modal_create_one' => 'Crie uma',
    'modal_step_of' => 'Etapa :step de :total',
    'modal_step_details' => 'Seus dados',
    'modal_step_security' => 'Segurança',
    'modal_step_finish' => 'Finalizar',
    'modal_full_name' => 'Nome completo',
    'modal_name_placeholder' => 'Seu nome',
    'modal_phone' => 'Número de telefone',
    'modal_dial_code' => 'Selecionar o código de discagem do país',
    'modal_search_country' => 'Buscar país ou código',
    'modal_no_match' => 'Nenhum resultado',
    'modal_password_hint' => 'Mais de 8 caracteres com letras maiúsculas, minúsculas, números e pelo menos um caractere especial',
    'modal_confirm_password' => 'Confirmar senha',
    'modal_gender' => 'Gênero',
    'modal_gender_male' => 'Masculino',
    'modal_gender_female' => 'Feminino',
    'modal_gender_other' => 'Outro',
    'modal_optional' => '(opcional)',
    'modal_referral_placeholder' => 'Digite o código de indicação de um amigo',
    'modal_terms_consent' => '* Declaro que li e concordo com os :terms e o :privacy',
    'modal_terms_link' => 'termos de uso',
    'modal_privacy_link' => 'aviso de privacidade',
    'modal_newsletter' => 'Não perca nosso próximo lançamento de cupons! Quero receber descontos exclusivos e códigos promocionais',
    'modal_create_account' => 'Criar conta',
    'modal_creating_account' => 'Criando conta...',
    'modal_have_account' => 'Já tem uma conta?',
    'modal_account_created' => 'Conta criada',
    'modal_verification_sent' => 'Enviamos um link de verificação para :email. Verifique seu e-mail para começar a fazer pedidos. Enquanto isso, você pode navegar à vontade.',
    'modal_no_email' => 'Não recebeu o e-mail?',
    'modal_resend' => 'Reenviar',
    'modal_sending' => 'Enviando...',
    'modal_resent' => 'Um novo link de verificação está a caminho.',
    'modal_resend_wait' => 'Aguarde um momento antes de solicitar outro link.',
    'modal_continue_account' => 'Continuar para minha conta',

    // Errors the modal's server side raises.
    'registrations_closed' => 'Novos cadastros estão temporariamente suspensos.',
    'phone_taken' => 'Este número de telefone já está cadastrado. Tente entrar na sua conta.',
    'security_unavailable' => 'A verificação de segurança está temporariamente indisponível. Tente novamente.',
    'security_failed' => 'A verificação de segurança falhou. Atualize a página e tente novamente.',

    // Field names for the modal's validation messages, so an error names the
    // field rather than the Livewire property behind it.
    'modal_attributes' => [
        'name' => 'nome',
        'email' => 'e-mail',
        'country' => 'país',
        'phone' => 'número de telefone',
        'password' => 'senha',
        'password_confirmation' => 'confirmação de senha',
        'gender' => 'gênero',
        'terms' => 'termos',
        'referral' => 'código de indicação',
    ],
];
