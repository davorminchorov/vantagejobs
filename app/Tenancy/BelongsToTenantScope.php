<?php

declare(strict_types=1);

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope that filters tenant-scoped Eloquent queries to the current
 * tenant.
 *
 * When no tenant is active in TenantContext, this scope is a no-op. This is
 * deliberate: artisan commands (migrations, schema:dump), unauthenticated
 * routes (the login page rendering zero tenant-scoped rows), and other
 * boot-time code paths must not blow up.
 *
 * Write-time enforcement lives in the BelongsToTenant trait's creating
 * observer, which throws RequiresTenantContextException when a tenant-scoped
 * model is created without an active tenant.
 */
final class BelongsToTenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);
        $current = $context->current();

        if ($current === null) {
            return;
        }

        $builder->where($model->qualifyColumn('tenant_id'), $current->id);
    }
}
