@component('mail::message')
# Source needs attention: {{ $sourceName }}

The **{{ $sourceName }}** source (`{{ $sourceKey }}`) has gone unhealthy.

@if (! empty($reasons))
**Why:**

@foreach ($reasons as $reason)
- {{ $reason }}
@endforeach
@endif

@if ($consecutiveFailures > 0)
**Consecutive failed runs:** {{ $consecutiveFailures }}
@endif

@if (! empty($lastRunAt))
**Last run:** {{ $lastRunAt }}
@endif

@if (! empty($lastError))
**Last error:**

@component('mail::panel')
{{ $lastError }}
@endcomponent
@endif

Check the source configuration and the parser before the next scheduled run.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
