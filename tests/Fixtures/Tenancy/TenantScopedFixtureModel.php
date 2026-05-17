<?php

declare(strict_types=1);

namespace Tests\Fixtures\Tenancy;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Fixture model used only by BelongsToTenantTest. Backed by the
 * `tenant_scoped_fixtures` table created in the test's setUp.
 */
final class TenantScopedFixtureModel extends Model
{
    use BelongsToTenant;

    protected $table = 'tenant_scoped_fixtures';

    protected $fillable = ['name', 'tenant_id'];

    public $timestamps = false;
}
