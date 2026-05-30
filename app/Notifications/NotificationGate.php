<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationKind;
use App\Models\NotificationLogEntry;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Shared send-gate for every outbound notification email.
 *
 * Every notification slice (daily digest, A-tier alert, weekly summary,
 * source health) funnels through this gate so throttle and do-not-disturb
 * logic lives in exactly one place. The gate answers three questions:
 *
 *  - Is a given moment inside the do-not-disturb window? (isWithinDnd)
 *  - When is the next minute a deferred email may be sent? (nextSendableMoment)
 *  - Has an email of this kind/company gone out recently? (recentlySent)
 *
 * It also appends the append-only notification_log ledger via recordSent().
 *
 * DND window, timezone and throttle values resolve from the Setting
 * key-value store first, falling back to config('notifications.*') so a
 * missing Setting row never breaks behavior.
 */
final readonly class NotificationGate
{
    public function __construct(
        private ConfigRepository $config,
    ) {}

    /**
     * Whether the given moment falls inside the do-not-disturb window in the
     * supplied timezone. Handles windows that wrap past midnight (e.g.
     * 22:00 -> 07:00).
     */
    public function isWithinDnd(CarbonImmutable $moment, string $timezone): bool
    {
        $local = $moment->setTimezone($timezone);

        $start = $this->timeSetting('dnd_start', 'notifications.dnd_start');
        $end = $this->timeSetting('dnd_end', 'notifications.dnd_end');

        $startMinutes = $this->minutesOfDay($start);
        $endMinutes = $this->minutesOfDay($end);
        $current = ($local->hour * 60) + $local->minute;

        // Non-wrapping window (e.g. 01:00 -> 06:00).
        if ($startMinutes <= $endMinutes) {
            return $current >= $startMinutes && $current < $endMinutes;
        }

        // Wrapping window (e.g. 22:00 -> 07:00): inside if before end OR after start.
        return $current >= $startMinutes || $current < $endMinutes;
    }

    /**
     * The next moment at or after the given one when an email may be sent.
     *
     * If the moment is outside DND it is returned unchanged. If inside DND it
     * is deferred (never dropped) to the start of the DND end boundary in the
     * recipient timezone, rolling to the next day when the window wraps.
     */
    public function nextSendableMoment(CarbonImmutable $moment, string $timezone): CarbonImmutable
    {
        if (! $this->isWithinDnd($moment, $timezone)) {
            return $moment;
        }

        $local = $moment->setTimezone($timezone);
        $end = $this->timeSetting('dnd_end', 'notifications.dnd_end');
        [$endHour, $endMinute] = $this->parseTimeParts($end);

        $candidate = $local->setTime($endHour, $endMinute, 0);

        // When the window wraps past midnight and we are still on the late
        // side of it (current time at/after the end boundary), the next end
        // boundary is tomorrow.
        if ($candidate->lessThanOrEqualTo($local)) {
            $candidate = $candidate->addDay();
        }

        return $candidate->setTimezone($moment->getTimezone());
    }

    /**
     * Whether an email of the given kind (optionally scoped to a company) was
     * recorded in the notification_log within the trailing window of minutes.
     *
     * Backs the A-tier "max 1 email per company per hour" throttle and the
     * source-health alert de-dup. When $withinMinutes is null the configured
     * a_tier_throttle_minutes value is used.
     */
    public function recentlySent(NotificationKind $kind, ?int $companyId = null, ?int $withinMinutes = null): bool
    {
        $minutes = $withinMinutes ?? $this->throttleMinutes();
        $threshold = CarbonImmutable::now()->subMinutes($minutes);

        $query = NotificationLogEntry::query()
            ->where('kind', $kind->value)
            ->where('sent_at', '>=', $threshold);

        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        return $query->exists();
    }

    /**
     * Append an entry to the notification_log ledger. Called by each
     * notification slice after a mail has been successfully queued.
     *
     * @param  array<string, mixed>  $context
     */
    public function recordSent(
        NotificationKind $kind,
        string $recipient,
        ?int $companyId = null,
        ?string $subject = null,
        array $context = [],
        ?CarbonImmutable $sentAt = null,
    ): NotificationLogEntry {
        $entry = new NotificationLogEntry;
        $entry->kind = $kind;
        $entry->company_id = $companyId;
        $entry->recipient = $recipient;
        $entry->subject = $subject;
        $entry->context = $context;
        $entry->sent_at = $sentAt ?? CarbonImmutable::now();
        $entry->save();

        return $entry;
    }

    /**
     * The configured A-tier throttle window in minutes (Setting override,
     * config fallback).
     */
    public function throttleMinutes(): int
    {
        $override = $this->setting('a_tier_throttle_minutes');

        if (is_numeric($override)) {
            return (int) $override;
        }

        return (int) $this->config->get('notifications.a_tier_throttle_minutes', 60);
    }

    /**
     * Resolve a wall-clock HH:MM setting, falling back to a config key.
     */
    private function timeSetting(string $settingKey, string $configKey): string
    {
        $override = $this->setting($settingKey);

        if (is_string($override) && $override !== '') {
            return $override;
        }

        return (string) $this->config->get($configKey);
    }

    /**
     * Read a tenant-scoped Setting value, or null when no row exists.
     */
    private function setting(string $key): mixed
    {
        return Setting::query()
            ->where('key', $key)
            ->value('value');
    }

    private function minutesOfDay(string $time): int
    {
        [$hour, $minute] = $this->parseTimeParts($time);

        return ($hour * 60) + $minute;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function parseTimeParts(string $time): array
    {
        $parts = explode(':', $time);
        $hour = (int) ($parts[0] ?? 0);
        $minute = (int) ($parts[1] ?? 0);

        return [$hour, $minute];
    }
}
