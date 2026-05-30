<?php

declare(strict_types=1);

namespace App\Http\Requests\Contacts;

use App\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateContactRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        // Rule::exists hits the query builder directly and bypasses the
        // Eloquent tenant global scope, so constrain it to the current tenant
        // by hand to prevent cross-tenant company references.
        $tenantId = app(TenantContext::class)->current()?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'role_title' => ['nullable', 'string', 'max:255'],
            'linkedin_url' => ['nullable', 'string', 'max:500', 'url'],
            'email' => ['nullable', 'string', 'max:255', 'email'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'company_id' => [
                'nullable',
                'integer',
                Rule::exists('companies', 'id')->where(
                    fn (Builder $query) => $query->where('tenant_id', $tenantId),
                ),
            ],
        ];
    }
}
