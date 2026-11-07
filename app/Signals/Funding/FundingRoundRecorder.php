<?php

declare(strict_types=1);

namespace App\Signals\Funding;

use App\Enums\EventType;
use App\Models\Company;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Records a funding round on a company: writes a FUNDING Event and, when
 * the round is at least as recent as the one on file, updates the
 * company's funding stage, amount and last-raised date. Shared by the
 * company page form and the funding candidates review queue.
 */
final readonly class FundingRoundRecorder
{
    public function __construct(private ConnectionInterface $db) {}

    /**
     * @param  array<string, mixed>  $context  Extra fields stored in the event body (source, candidate id, ...).
     */
    public function record(
        Company $company,
        ?string $stage,
        ?int $amountUsd,
        ?CarbonImmutable $raisedAt,
        ?string $url,
        ?string $notes,
        array $context,
        ?string $title = null,
    ): Event {
        return $this->db->transaction(function () use ($company, $stage, $amountUsd, $raisedAt, $url, $notes, $context, $title): Event {
            // Only overwrite Company-level scalars when the new round is at
            // least as recent as the stored one. Stale historical entries
            // shouldn't downgrade the company's "most recent" view.
            $shouldUpdate = $raisedAt !== null && (
                $company->funding_last_raised_at === null
                || $raisedAt->greaterThanOrEqualTo($company->funding_last_raised_at)
            );
            if ($shouldUpdate) {
                $company->fill([
                    'funding_stage' => $stage ?? $company->funding_stage,
                    'funding_last_raised_at' => $raisedAt,
                    'funding_last_amount_usd' => $amountUsd ?? $company->funding_last_amount_usd,
                ])->save();
            }

            return Event::query()->create([
                'company_id' => $company->id,
                'role_id' => null,
                'type' => EventType::FUNDING->value,
                'title' => $title ?? self::defaultTitle($stage, $amountUsd),
                'body' => (string) json_encode(array_filter([
                    'stage' => $stage,
                    'amount_usd' => $amountUsd,
                    'raised_at' => $raisedAt?->toIso8601String(),
                    'url' => $url,
                    'notes' => $notes,
                    ...$context,
                ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []), JSON_THROW_ON_ERROR),
                'occurred_at' => $raisedAt ?? CarbonImmutable::now(),
            ]);
        });
    }

    private static function defaultTitle(?string $stage, ?int $amountUsd): string
    {
        if ($stage !== null) {
            return "Funding round: {$stage}".($amountUsd !== null ? ' — '.self::humanAmount($amountUsd) : '');
        }

        return $amountUsd !== null ? 'Funding round — '.self::humanAmount($amountUsd) : 'Funding round';
    }

    private static function humanAmount(int $usd): string
    {
        if ($usd >= 1_000_000_000) {
            return '$'.number_format($usd / 1_000_000_000, 1).'B';
        }
        if ($usd >= 1_000_000) {
            return '$'.number_format($usd / 1_000_000, 1).'M';
        }
        if ($usd >= 1_000) {
            return '$'.number_format($usd / 1_000).'k';
        }

        return '$'.number_format($usd);
    }
}
