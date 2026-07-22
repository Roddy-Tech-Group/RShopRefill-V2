<x-mail::message>
# Security Alert: {{ $eventType }}

{{ $details }}

If this activity was not you, secure your account immediately and contact support.

<x-mail::button :url="url('/account/security')">
Review Security Settings
</x-mail::button>

Thanks,<br>
{{ config('app.name') }} Security
</x-mail::message>
