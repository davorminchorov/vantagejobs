@component('mail::message')
# Daily digest

{{ $localDate }} ({{ $timezone }})

@if ($eventCount > 0)
## New activity ({{ $eventCount }})

@foreach ($digest->companyActivity as $activity)
**{{ \App\Support\MarkdownText::escape($activity->companyName) }}** · Tier {{ $activity->tier }}

@foreach ($activity->events as $event)
- {{ \App\Support\MarkdownText::escape($event->title) }}
@endforeach

@endforeach
@else
No new company activity in the last 24 hours.
@endif

## At a glance

- **Discover queue:** {{ $digest->discoverQueueCount }} listing(s) awaiting triage
- **Applications needing attention:** {{ count($digest->attentionItems) }}
- **Stale A-tier:** {{ $digest->staleATierCompanies->count() }} company(ies)

@if ($digest->staleATierCompanies->isNotEmpty())
## Stale A-tier companies

@foreach ($digest->staleATierCompanies as $company)
- **{{ \App\Support\MarkdownText::escape($company->name) }}**: {{ $company->last_checked_at ? 'last checked '.$company->last_checked_at->setTimezone($timezone)->format('M j, Y') : 'never checked' }}
@endforeach
@endif

@if ($digest->attentionItems !== [])
## Applications needing attention

@foreach ($digest->attentionItems as $item)
- [{{ \App\Support\MarkdownText::escape($item->title) }}@if ($item->companyName), {{ \App\Support\MarkdownText::escape($item->companyName) }}@endif]({{ $item->url() }}): {{ \App\Support\MarkdownText::escape($item->describe()) }}
@endforeach
@endif

Thanks,<br>
{{ config('app.name') }}
@endcomponent
