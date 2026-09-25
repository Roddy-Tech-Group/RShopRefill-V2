<?php

/**
 * Sign-in, registration and account security screens.
 *
 * The framework auth keys (failed / password / throttle) live here too, under
 * the names Laravel looks up. Putting them anywhere else means a failed login
 * falls back to English whatever language the customer chose.
 */
return [
    'failed' => 'These credentials do not match our records.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',
    'sign_in' => 'Login',
    'sign_up' => 'Sign Up',
    'create_account' => 'Create Account',
    'email' => 'Email Address',
    'password_label' => 'Password',
    'confirm_password' => 'Confirm Password',
    'full_name' => 'Full Name',
    'first_name' => 'First name',
    'last_name' => 'Last name',
    'referral_code' => 'Referral code',
    'forgot_password' => 'Forgot password?',
    'remember_me' => 'Remember me',
    'continue_with_google' => 'Continue with Google',
    'or' => 'or',
    'agree_to_terms' => 'I agree to the :terms and :privacy.',
    'agree_required' => 'Please accept the Terms of Service and Privacy Policy to continue.',
    'privacy_note' => 'Your information is protected and handled according to our :privacy',
    'password_requirements' => 'At least 8 characters, including one number.',
    'password_min_length' => 'At least 8 characters',
    'password_has_number' => 'Includes a number',
    'brand_tagline' => 'Your digital world, all in one place.',
    'brand_subtitle' => 'Gift Cards, eSIMs, Mobile Refills & Bill Payments.',

    // The sign-in / register modal (livewire/auth/auth-modal). :site is the
    // site name and :email the address just registered - neither is translated.
    'modal_log_in' => 'Log in',
    'modal_register' => 'Register',
    'modal_welcome_aboard' => 'Welcome aboard',
    'modal_join' => 'Join :site and start spending instantly',
    'modal_welcome_back' => 'Welcome back',
    'modal_sign_in_to' => 'Sign in to your :site account',
    'modal_email' => 'Email',
    'modal_show_password' => 'Show password',
    'modal_hide_password' => 'Hide password',
    'modal_signing_in' => 'Signing in...',
    'modal_no_account' => 'Don\'t have an account?',
    'modal_create_one' => 'Create one',
    'modal_step_of' => 'Step :step of :total',
    'modal_step_details' => 'Your details',
    'modal_step_security' => 'Security',
    'modal_step_finish' => 'Finish',
    'modal_full_name' => 'Full name',
    'modal_name_placeholder' => 'Your name',
    'modal_phone' => 'Phone number',
    'modal_dial_code' => 'Select country dialing code',
    'modal_search_country' => 'Search country or code',
    'modal_no_match' => 'No match',
    'modal_password_hint' => 'More than 8 characters with upper, lower, numbers and at least one special character',
    'modal_confirm_password' => 'Confirm password',
    'modal_gender' => 'Gender',
    'modal_gender_male' => 'Male',
    'modal_gender_female' => 'Female',
    'modal_gender_other' => 'Other',
    'modal_optional' => '(optional)',
    'modal_referral_placeholder' => 'Enter a friend\'s referral code',
    'modal_terms_consent' => '* I declare that I have read and agree to the :terms and :privacy',
    'modal_terms_link' => 'terms of use',
    'modal_privacy_link' => 'privacy notice',
    'modal_newsletter' => 'Don\'t miss out on our next coupon drop! Sign me up for exclusive discounts and promo codes',
    'modal_create_account' => 'Create account',
    'modal_creating_account' => 'Creating account...',
    'modal_have_account' => 'Already have an account?',
    'modal_account_created' => 'Account created',
    'modal_verification_sent' => 'We sent a verification link to :email. Verify your email to start ordering. You can browse in the meantime.',
    'modal_no_email' => 'Didn\'t get the email?',
    'modal_resend' => 'Resend it',
    'modal_sending' => 'Sending...',
    'modal_resent' => 'A fresh verification link is on its way.',
    'modal_resend_wait' => 'Please wait a moment before requesting another link.',
    'modal_continue_account' => 'Continue to my account',

    // Errors the modal's server side raises.
    'registrations_closed' => 'New registrations are temporarily closed.',
    'phone_taken' => 'This phone number is already registered. Try logging in instead.',
    'security_unavailable' => 'Security check is temporarily unavailable. Please try again.',
    'security_failed' => 'Security verification failed. Please refresh and try again.',

    // Field names for the modal's validation messages, so an error names the
    // field rather than the Livewire property behind it.
    'modal_attributes' => [
        'name' => 'name',
        'email' => 'email',
        'country' => 'country',
        'phone' => 'phone number',
        'password' => 'password',
        'password_confirmation' => 'password confirmation',
        'gender' => 'gender',
        'terms' => 'terms',
        'referral' => 'referral code',
    ],
];
