@component('mail::message')
# Transaction PIN Reset Request

Hi {{ $user->name }},

We received a request to reset your wallet transaction PIN.

Click the button below to set a new PIN. This link will expire in 1 hour.

@component('mail::button', ['url' => route('dashboard.security.pin-reset', ['token' => $token])])
Reset PIN
@endcomponent

If you did not request a PIN reset, please ignore this email or contact support if you have concerns.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
