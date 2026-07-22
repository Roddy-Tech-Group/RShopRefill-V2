<x-mail::message>
# Your account has been recovered

Your account access has been successfully restored.

**Recovery request IP:** {{ $ipAddress }}

If you did not perform this recovery, contact support immediately — your account may be at risk.

<x-mail::button :url="url('/account/security')">
Review Security Settings
</x-mail::button>

Thanks,<br>
{{ config('app.name') }} Security
</x-mail::message>
