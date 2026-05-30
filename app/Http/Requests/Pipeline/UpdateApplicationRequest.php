<?php

declare(strict_types=1);

namespace App\Http\Requests\Pipeline;

use App\Enums\ApplicationSource;
use App\Models\Contact;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $sources = array_map(fn (ApplicationSource $s) => $s->value, ApplicationSource::cases());

        // Referral contact must belong to the current tenant. The BelongsToTenant
        // global scope already filters Contact reads, so an existence check that
        // routes through the model honours tenant isolation automatically.
        $tenantContactIds = Contact::query()->pluck('id')->all();

        return [
            'applied_at' => ['nullable', 'date'],
            'application_source' => ['nullable', Rule::in($sources)],
            'referral_contact_id' => ['nullable', 'integer', Rule::in($tenantContactIds)],
            'next_action' => ['nullable', 'string', 'max:255'],
            'next_action_date' => ['nullable', 'date'],
            'general_notes' => ['nullable', 'string', 'max:10000'],
            'interview_prep' => ['nullable', 'string', 'max:50000'],
        ];
    }
}
