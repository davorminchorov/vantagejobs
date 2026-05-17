<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Tenancy\RequiresTenantContextException;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;

final class ResolveTenantContext
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $tenantId = $user->getAttribute('tenant_id');

        if (empty($tenantId)) {
            return $next($request);
        }

        $tenant = Tenant::query()->find($tenantId);

        if ($tenant === null) {
            throw new RequiresTenantContextException(
                "Authenticated user {$user->getKey()} references missing tenant {$tenantId}."
            );
        }

        $this->context->setCurrent($tenant);

        return $next($request);
    }
}
