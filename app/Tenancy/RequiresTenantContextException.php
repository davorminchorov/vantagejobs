<?php

declare(strict_types=1);

namespace App\Tenancy;

use RuntimeException;

final class RequiresTenantContextException extends RuntimeException
{
    public static function forModel(string $modelClass): self
    {
        return new self(
            "Cannot write {$modelClass} without an active tenant context. Call TenantContext::setCurrent() first."
        );
    }
}
