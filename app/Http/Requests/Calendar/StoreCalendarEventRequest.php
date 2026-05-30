<?php

declare(strict_types=1);

namespace App\Http\Requests\Calendar;

use App\Enums\CalendarEventType;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class StoreCalendarEventRequest extends FormRequest
{
    /**
     * Morph-map keys an event may be attached to. Mirrors the enforced morph
     * map in AppServiceProvider; owner is optional (standalone reminders).
     *
     * @var array<int, string>
     */
    private const OWNER_TYPES = ['role', 'company', 'contact'];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'string'],
            'timezone' => ['required', 'string', Rule::in($this->validTimezones())],
            'type' => ['required', Rule::enum(CalendarEventType::class)],
            'owner_type' => ['nullable', 'string', Rule::in(self::OWNER_TYPES)],
            'owner_id' => ['nullable', 'integer', 'required_with:owner_type'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'url' => ['nullable', 'string', 'max:1024', 'url'],
            'reminder_minutes' => ['nullable', 'integer', 'min:0', 'max:40320'],
        ];
    }

    /**
     * Validate that starts_at parses in the given timezone, and that any
     * referenced owner row exists within the current tenant. Rule::exists
     * bypasses the Eloquent tenant scope, so the tenant clause is added by
     * hand to block cross-tenant owner references.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $startsAt = $this->input('starts_at');
            $timezone = $this->input('timezone');

            if (is_string($startsAt) && is_string($timezone) && in_array($timezone, $this->validTimezones(), true)) {
                try {
                    new \DateTimeImmutable($startsAt, new \DateTimeZone($timezone));
                } catch (\Exception) {
                    $validator->errors()->add('starts_at', 'The starts at field is not a valid date or time.');
                }
            }

            $ownerType = $this->input('owner_type');
            $ownerId = $this->input('owner_id');

            if (is_string($ownerType) && in_array($ownerType, self::OWNER_TYPES, true) && $ownerId !== null) {
                $tenantId = app(TenantContext::class)->current()?->id;
                $table = $this->ownerTable($ownerType);

                $exists = DB::table($table)
                    ->where('id', (int) $ownerId)
                    ->where('tenant_id', $tenantId)
                    ->exists();

                if (! $exists) {
                    $validator->errors()->add('owner_id', 'The selected owner is invalid.');
                }
            }
        });
    }

    private function ownerTable(string $ownerType): string
    {
        return match ($ownerType) {
            'role' => 'roles',
            'company' => 'companies',
            'contact' => 'contacts',
        };
    }

    /**
     * @return array<int, string>
     */
    private function validTimezones(): array
    {
        return \DateTimeZone::listIdentifiers();
    }
}
