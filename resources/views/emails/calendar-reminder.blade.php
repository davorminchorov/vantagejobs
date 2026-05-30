@component('mail::message')
# {{ $title }}

This event is coming up.

**When:** {{ $localStartTime }}

@if (! empty($notes))
{{ $notes }}
@endif

@if (! empty($url))
@component('mail::button', ['url' => $url])
Open
@endcomponent
@endif

Thanks,<br>
{{ config('app.name') }}
@endcomponent
