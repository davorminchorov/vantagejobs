<?php

declare(strict_types=1);

namespace App\Tenancy;

final class TenantContext
{
    private ?Tenant $current = null;

    public function current(): ?Tenant
    {
        return $this->current;
    }

    public function currentOrFail(): Tenant
    {
        if ($this->current === null) {
            throw new RequiresTenantContextException(
                'No active tenant context. Call TenantContext::setCurrent() first.'
            );
        }

        return $this->current;
    }

    public function setCurrent(?Tenant $tenant): void
    {
        $this->current = $tenant;
    }

    /**
     * Run a callback inside the scope of a given tenant, restoring the
     * previous tenant context (or null) afterwards, even on exception.
     */
    public function withTenant(Tenant $tenant, callable $callback): mixed
    {
        $previous = $this->current;
        $this->current = $tenant;

        try {
            return $callback();
        } finally {
            $this->current = $previous;
        }
    }

    public function has(): bool
    {
        return $this->current !== null;
    }
}
