@component('mail::message')
# Reset your password

Hi {{ $name }},

Someone — hopefully you — asked to reset the password for your Business Management Suite account{{ $workspace ? ' in the ' . $workspace . ' workspace' : '' }}.

@component('mail::button', ['url' => $resetUrl, 'color' => 'primary'])
Choose a new password
@endcomponent

This link works once and expires in {{ $ttlMinutes }} minutes. If the button doesn't work, paste this into your browser:
[{{ $resetUrl }}]({{ $resetUrl }})

If you didn't ask for this, ignore this email — your current password keeps working and nothing changes.

Business Management Suite
@endcomponent
