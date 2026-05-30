{{--
    Shared notification email shell.

    Wraps the standard Laravel Markdown `mail::message` component with a
    consistent heading and footer so the daily digest, A-tier alert, weekly
    summary and source-health emails read as one family.

    Slots / variables:
      $heading   string  Top-level <h1> heading (required).
      $intro     string  Optional lead paragraph under the heading.
      $slot      mixed   Markdown body content (the @component default slot).
      $footnote  string  Optional small print above the standard sign-off.
--}}
@component('mail::message')
# {{ $heading }}

@isset($intro)
{{ $intro }}
@endisset

{{ $slot }}

@isset($footnote)
@component('mail::subcopy')
{{ $footnote }}
@endcomponent
@endisset

Thanks,<br>
{{ config('app.name') }}
@endcomponent
