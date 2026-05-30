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
| Follow-ups due | {{ $followUpsDue }} |
@endcomponent

@if (! empty($topSignals))
## Top signals of the week

@foreach ($topSignals as $signal)
- **{{ $signal['label'] }}**@if (! empty($signal['company'])) · {{ $signal['company'] }}@endif@if (! empty($signal['title'])) — {{ $signal['title'] }}@endif

@endforeach
@endif

@if (! empty($pipelineTrend))
## Pipeline trend

This week versus the week before.

@component('mail::table')
| Stage | This week | Last week | Change |
|:------|----------:|----------:|:-------|
@foreach ($pipelineTrend as $row)
| {{ $row['label'] }} | {{ $row['current'] }} | {{ $row['prior'] }} | {{ $row['delta'] > 0 ? '+'.$row['delta'] : $row['delta'] }} |
@endforeach
@endcomponent
@endif

@component('mail::button', ['url' => route('dashboard')])
Open dashboard
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
