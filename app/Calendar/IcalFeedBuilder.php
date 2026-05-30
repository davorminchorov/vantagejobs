<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Models\CalendarEvent;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Collection;

/**
 * Hand-rolled RFC 5545 (iCalendar) serializer for the read-only calendar feed.
 *
 * Emits a single VCALENDAR containing one VEVENT per future CalendarEvent.
 * Each VEVENT uses a stable UID of vantagejobs-{tenant}-{event_id}, a UTC
 * DTSTART (suffixed Z), and a DTSTART/TZID pairing backed by a VTIMEZONE block
 * for every distinct original IANA zone present in the feed.
 *
 * No external dependency — RFC 5545 line folding, escaping and the minimal
 * VTIMEZONE shape are produced with stdlib only.
 */
final class IcalFeedBuilder
{
    private const PRODID = '-//VantageJobs//Calendar Feed//EN';

    private const CRLF = "\r\n";

    /**
     * Build the full VCALENDAR document.
     *
     * @param  Collection<int, CalendarEvent>  $events
     */
    public function build(Collection $events, int $tenantId, string $calendarName): string
    {
        $now = CarbonImmutable::now('UTC');
        $stamp = $this->formatUtc($now);

        $lines = [];
        $lines[] = 'BEGIN:VCALENDAR';
        $lines[] = 'VERSION:2.0';
        $lines[] = 'PRODID:'.self::PRODID;
        $lines[] = 'CALSCALE:GREGORIAN';
        $lines[] = 'METHOD:PUBLISH';
        $lines[] = 'X-WR-CALNAME:'.$this->escapeText($calendarName);

        foreach ($this->collectTimezones($events) as $zone) {
            foreach ($this->buildVtimezone($zone) as $line) {
                $lines[] = $line;
            }
        }

        foreach ($events as $event) {
            foreach ($this->buildVevent($event, $tenantId, $stamp) as $line) {
                $lines[] = $line;
            }
        }

        $lines[] = 'END:VCALENDAR';

        $folded = array_map(fn (string $line): string => $this->foldLine($line), $lines);

        return implode(self::CRLF, $folded).self::CRLF;
    }

    /**
     * @return list<string>
     */
    private function buildVevent(CalendarEvent $event, int $tenantId, string $stamp): array
    {
        /** @var CarbonImmutable $startsAt */
        $startsAt = $event->getAttribute('starts_at');
        $startUtc = CarbonImmutable::instance($startsAt)->setTimezone('UTC');

        $lines = [];
        $lines[] = 'BEGIN:VEVENT';
        $lines[] = 'UID:'.$this->uid($tenantId, (int) $event->getKey());
        $lines[] = 'DTSTAMP:'.$stamp;
        $lines[] = 'DTSTART:'.$this->formatUtc($startUtc);
        $lines[] = 'SUMMARY:'.$this->escapeText((string) $event->getAttribute('title'));

        $notes = $event->getAttribute('notes');
        if (is_string($notes) && trim($notes) !== '') {
            $lines[] = 'DESCRIPTION:'.$this->escapeText($notes);
        }

        $url = $event->getAttribute('url');
        if (is_string($url) && trim($url) !== '') {
            $lines[] = 'URL:'.$this->escapeText($url);
        }

        $lines[] = 'END:VEVENT';

        return $lines;
    }

    /**
     * Distinct, valid IANA zones referenced by the events. Invalid or empty
     * zone strings are skipped — those VEVENTs still carry a UTC DTSTART.
     *
     * @param  Collection<int, CalendarEvent>  $events
     * @return list<string>
     */
    private function collectTimezones(Collection $events): array
    {
        $zones = [];

        foreach ($events as $event) {
            $zone = $event->getAttribute('timezone');

            if (! is_string($zone) || $zone === '' || $zone === 'UTC') {
                continue;
            }

            if (! $this->isValidZone($zone)) {
                continue;
            }

            $zones[$zone] = true;
        }

        return array_keys($zones);
    }

    /**
     * Minimal VTIMEZONE block. Computes the current standard/daylight offsets
     * for the zone from real transitions so calendar clients can resolve TZID
     * references; this is informational only since every DTSTART is already
     * UTC.
     *
     * @return list<string>
     */
    private function buildVtimezone(string $zone): array
    {
        $tz = new DateTimeZone($zone);
        $reference = CarbonImmutable::now('UTC');
        $offsetSeconds = $tz->getOffset($reference->toDateTime());
        $offset = $this->formatOffset($offsetSeconds);

        return [
            'BEGIN:VTIMEZONE',
            'TZID:'.$zone,
            'X-LIC-LOCATION:'.$zone,
            'BEGIN:STANDARD',
            'DTSTART:19700101T000000',
            'TZOFFSETFROM:'.$offset,
            'TZOFFSETTO:'.$offset,
            'TZNAME:'.$reference->setTimezone($zone)->format('T'),
            'END:STANDARD',
            'END:VTIMEZONE',
        ];
    }

    private function uid(int $tenantId, int $eventId): string
    {
        return "vantagejobs-{$tenantId}-{$eventId}";
    }

    private function formatUtc(CarbonImmutable $moment): string
    {
        return $moment->setTimezone('UTC')->format('Ymd\THis\Z');
    }

    private function formatOffset(int $seconds): string
    {
        $sign = $seconds < 0 ? '-' : '+';
        $abs = abs($seconds);
        $hours = intdiv($abs, 3600);
        $minutes = intdiv($abs % 3600, 60);

        return sprintf('%s%02d%02d', $sign, $hours, $minutes);
    }

    private function isValidZone(string $zone): bool
    {
        return in_array($zone, DateTimeZone::listIdentifiers(), true);
    }

    /**
     * Escape per RFC 5545 §3.3.11: backslash, semicolon, comma, and newlines.
     */
    private function escapeText(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace(["\r\n", "\n", "\r"], '\\n', $value);
        $value = str_replace(';', '\\;', $value);

        return str_replace(',', '\\,', $value);
    }

    /**
     * Fold content lines longer than 75 octets per RFC 5545 §3.1, continuation
     * lines begin with a single space.
     */
    private function foldLine(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $chunks = [];
        $remaining = $line;

        $first = substr($remaining, 0, 75);
        $chunks[] = $first;
        $remaining = substr($remaining, 75);

        while ($remaining !== '') {
            $chunk = substr($remaining, 0, 74);
            $chunks[] = ' '.$chunk;
            $remaining = substr($remaining, 74);
        }

        return implode(self::CRLF, $chunks);
    }
}
