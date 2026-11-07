<?php

declare(strict_types=1);

namespace App\Pipeline;

use App\Enums\AttentionReason;

/**
 * One application that needs attention, flattened for dashboard rows and emails.
 */
final readonly class AttentionItem
{
    public function __construct(
        public int $roleId,
        public string $title,
        public ?string $companyName,
        public AttentionReason $reason,
        public ?int $daysQuiet,
    ) {}

    public function describe(): string
    {
        return $this->reason->describe($this->daysQuiet);
    }

    public function url(): string
    {
        return route('applications.index', ['role' => $this->roleId]);
    }
}
