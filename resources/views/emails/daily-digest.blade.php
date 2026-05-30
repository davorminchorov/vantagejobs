@component('mail::message')
# Daily digest

{{ $localDate }} ({{ $timezone }})

@if ($eventCount > 0)
## New activity ({{ $eventCount }})

@foreach ($digest->companyActivity as $activity)
**{{ $activity->companyName }}** · Tier {{ $activity->tier }}

@foreach ($activity->events as $event)
- {{ $event->title }}
@endforeach

@endforeach
@else
No new company activity in the last 24 hours.
@endif

## At a glance

- **Discover queue:** {{ $digest->discoverQueueCount }} listing(s) awaiting triage
- **Follow-ups due:** {{ $digest->followUpQueueCount }} contact(s)
- **Stale A-tier:** {{ $digest->staleATierCompanies->count() }} company(ies)

@if ($digest->staleATierCompanies->isNotEmpty())
## Stale A-tier companies

@foreach ($digest->staleATierCompanies as $company)
- **{{ $company->name }}** — {{ $company->last_checked_at ? 'last checked '.$company->last_checked_at->setTimezone($timezone)->format('M j, Y') : 'never checked' }}
@endforeach
@endif

Thanks,<br>
{{ config('app.name') }}
@endcomponent
