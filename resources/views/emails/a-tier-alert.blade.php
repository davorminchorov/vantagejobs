@component('mail::message')
# New roles at {{ $companyName }}

This A-tier company just posted {{ $roles->count() }} new {{ $roles->count() === 1 ? 'role' : 'roles' }}.

@foreach ($roles as $role)
**{{ $role->title }}**@if (! empty($role->location)) — {{ $role->location }}@endif
@if (! empty($role->url))

@component('mail::button', ['url' => $role->url])
View role
@endcomponent
@endif
@endforeach

Thanks,<br>
{{ config('app.name') }}
@endcomponent
