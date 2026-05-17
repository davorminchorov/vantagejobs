<?php

declare(strict_types=1);

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Apply to every Eloquent model that owns rows belonging to a single tenant.
 *
 * Behaviors added:
 *  - Global query scope filtering reads to the current tenant.
 *  - `creating` observer that auto-fills tenant_id from TenantContext, or
 *    throws RequiresTenantContextException if no tenant is active.
 *  - `tenant()` BelongsTo relationship.
 *  - `withoutTenantScope()` static helper that returns a builder with the
 *    global scope removed. Reserve for tests and admin contexts.
 *
 * @mixin Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new BelongsToTenantScope);

        static::creating(function (Model $model): void {
            if (! empty($model->getAttribute('tenant_id'))) {
                return;
            }

            $context = app(TenantContext::class);
            $current = $context->current();

            if ($current === null) {
                throw RequiresTenantContextException::forModel(static::class);
            }

            $model->setAttribute('tenant_id', $current->id);
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public static function withoutTenantScope(): Builder
    {
        return static::query()->withoutGlobalScope(BelongsToTenantScope::class);
    }
}
