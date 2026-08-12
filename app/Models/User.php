<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['tenant_id', 'name', 'email', 'password', 'timezone', 'calendar_feed_token'])]
#[Hidden(['password', 'remember_token', 'two_factor_recovery_codes', 'two_factor_secret', 'calendar_feed_token'])]
final class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use BelongsToTenant;

    use HasFactory;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * Role names attached to this user, loaded lazily and cached for the
     * lifetime of this instance (never statically, so nothing leaks across
     * Octane requests) so repeated hasRole() calls issue at most one
     * indexed pivot query.
     *
     * @var list<string>|null
     */
    private ?array $roleNames = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * @return BelongsToMany<AuthRole, $this>
     */
    public function authRoles(): BelongsToMany
    {
        return $this->belongsToMany(AuthRole::class, 'auth_role_user');
    }

    public function hasRole(string $name): bool
    {
        return in_array($name, $this->roleNames(), true);
    }

    /**
     * @return list<string>
     */
    private function roleNames(): array
    {
        if ($this->roleNames === null) {
            $this->roleNames = $this->authRoles()->pluck('name')->all();
        }

        return $this->roleNames;
    }
}
