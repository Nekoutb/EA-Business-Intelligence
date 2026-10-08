@component('mail::message')
# Your password was changed

Hi {{ $name }},

The password for **{{ $email }}**{{ $workspace ? ' in the ' . $workspace . ' workspace' : '' }} was changed on {{ $when }}. Every device that was signed in has been signed out.

If this was you, there's nothing to do.

**If it wasn't you**, contact your administrator immediately — someone may have access to your email account.

Business Management Suite
@endcomponent
