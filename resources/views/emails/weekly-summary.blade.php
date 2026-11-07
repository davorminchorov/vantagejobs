@component('mail::message')
# Your week in review

@if (! empty($userName))
Hi {{ $userName }},
@endif

Here's what happened between {{ $windowStartLabel }} and {{ $windowEndLabel }}.

@component('mail::table')
| Metric | Count |
|:-------|------:|
| New roles | {{ $newRoles }} |
| Companies added | {{ $companiesAdded }} |
| Applications sent | {{ $applicationsSent }} |
| Offers recorded | {{ $offersRecorded }} |
| Applications needing attention | {{ count($needsAttention) }} |
@endcomponent

@if (! empty($needsAttention))
## Applications needing attention

@foreach ($needsAttention as $item)
- [{{ \App\Support\MarkdownText::escape($item['title']) }}@if (! empty($item['company'])), {{ \App\Support\MarkdownText::escape($item['company']) }}@endif]({{ $item['url'] }}): {{ \App\Support\MarkdownText::escape($item['reason']) }}
@endforeach
@endif

@if (! empty($topSignals))
## Top signals of the week

@foreach ($topSignals as $signal)
- **{{ \App\Support\MarkdownText::escape($signal['label']) }}**@if (! empty($signal['company'])) · {{ \App\Support\MarkdownText::escape($signal['company']) }}@endif @if (! empty($signal['title'])): {{ \App\Support\MarkdownText::escape($signal['title']) }}@endif

@endforeach
@endif

@if (! empty($pipelineTrend))
## Pipeline trend

This week versus the week before.

@component('mail::table')
| Stage | This week | Last week | Change |
|:------|----------:|----------:|:-------|
@foreach ($pipelineTrend as $row)
| {{ \App\Support\MarkdownText::escape($row['label']) }} | {{ $row['current'] }} | {{ $row['prior'] }} | {{ $row['delta'] > 0 ? '+'.$row['delta'] : $row['delta'] }} |
@endforeach
@endcomponent
@endif

@component('mail::button', ['url' => route('dashboard')])
Open dashboard
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
