<?php

declare(strict_types=1);

namespace App\Http\Controllers\Contacts;

use App\Http\Requests\Contacts\ComposeRequest;
use App\Models\Contact;
use App\Models\Template;
use App\Outreach\TemplateRenderer;
use Illuminate\Http\JsonResponse;

/**
 * Render an outreach template against a contact's data.
 *
 * Resolves the contact and template by integer id (no route-model
 * binding); both are tenant-scoped via the global BelongsToTenant scope,
 * so a cross-tenant id 404s. Builds the {{token}} variable map from the
 * contact and returns the substituted markdown for client-side editing
 * and copy-to-clipboard. Nothing is sent.
 */
final readonly class ComposeController
{
    public function __construct(private TemplateRenderer $renderer) {}

    public function __invoke(ComposeRequest $request, int $contact): JsonResponse
    {
        /** @var Contact $contactModel */
        $contactModel = Contact::query()->with('company')->findOrFail($contact);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        /** @var Template $template */
        $template = Template::query()->findOrFail((int) $data['template_id']);

        $variables = $this->variablesFor($contactModel);

        return new JsonResponse([
            'template' => [
                'id' => $template->id,
                'name' => $template->name,
                'category' => $template->category->value,
            ],
            'variables' => $variables,
            'rendered' => $this->renderer->render($template->body, $variables),
        ]);
    }

    /**
     * Build the substitution map from the contact. First/last name are
     * split on the first space; trailing tokens fold into the last name.
     *
     * @return array<string, string|null>
     */
    private function variablesFor(Contact $contact): array
    {
        [$firstName, $lastName] = $this->splitName($contact->name);

        return [
            'contact_first_name' => $firstName,
            'contact_last_name' => $lastName,
            'company' => $contact->company?->name,
            'role' => $contact->role_title,
        ];
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function splitName(string $name): array
    {
        $trimmed = trim($name);

        if ($trimmed === '') {
            return ['', null];
        }

        $space = mb_strpos($trimmed, ' ');

        if ($space === false) {
            return [$trimmed, null];
        }

        $first = mb_substr($trimmed, 0, $space);
        $last = trim(mb_substr($trimmed, $space + 1));

        return [$first, $last === '' ? null : $last];
    }
}
