@component('mail::message')
# Welcome to {{ $brand }}

Hi {{ $name }},

A user account has just been created for you on **{{ $brand }}**@if($tenantName) ({{ $tenantName }} workspace)@endif. Your initial credentials are below — change the password on first login.

@component('mail::panel')
**Email:** {{ $email }}
**Temporary password:** `{{ $password }}`
**Role:** {{ $role }}
@endcomponent

@component('mail::button', ['url' => $loginUrl, 'color' => 'primary'])
Sign in
@endcomponent

If the button doesn't work, paste this into your browser:
[{{ $loginUrl }}]({{ $loginUrl }})

When you sign in for the first time you'll be asked to set a password of your own before anything else — the temporary one above stops working the moment you save it.

If you weren't expecting this email, please reply to your administrator — somebody created an account for you in error.

Thanks,
{{ $brand }}
@endcomponent
