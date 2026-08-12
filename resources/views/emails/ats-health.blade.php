@component('mail::message')
# ATS health needs attention: {{ $companyName }}

**{{ $companyName }}** has gone unhealthy on ATS tracking.

@if (! empty($reasons))
**Why:**

@foreach ($reasons as $reason)
- {{ $reason }}
@endforeach
@endif

@if ($consecutive404s > 0)
**Consecutive 404s:** {{ $consecutive404s }}
@endif

@if (! empty($lastRunAt))
**Last run:** {{ $lastRunAt }}
@endif

@component('mail::button', ['url' => $companyUrl])
View company
@endcomponent

Check the company's ATS type and slug before the next scheduled run.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
