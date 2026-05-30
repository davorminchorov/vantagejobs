<?php

declare(strict_types=1);

namespace Tests\Feature\Outreach;

use App\Outreach\TemplateRenderer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TemplateRendererTest extends TestCase
{
    private TemplateRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new TemplateRenderer;
    }

    #[Test]
    public function it_substitutes_every_known_token(): void
    {
        $body = 'Hi {{contact_first_name}} {{contact_last_name}}, I saw {{company}} is hiring a {{role}}.';

        $rendered = $this->renderer->render($body, [
            'contact_first_name' => 'Ada',
            'contact_last_name' => 'Lovelace',
            'company' => 'Acme',
            'role' => 'Staff Engineer',
        ]);

        $this->assertSame('Hi Ada Lovelace, I saw Acme is hiring a Staff Engineer.', $rendered);
    }

    #[Test]
    public function it_leaves_unknown_tokens_verbatim(): void
    {
        $body = 'Hi {{contact_first_name}}, your {{unknown_token}} stays.';

        $rendered = $this->renderer->render($body, [
            'contact_first_name' => 'Grace',
        ]);

        $this->assertSame('Hi Grace, your {{unknown_token}} stays.', $rendered);
    }

    #[Test]
    public function it_renders_missing_and_null_values_as_empty_strings(): void
    {
        $body = 'A:{{company}} B:{{role}} C:{{contact_last_name}}';

        $rendered = $this->renderer->render($body, [
            'company' => null,
            // role and contact_last_name absent entirely
        ]);

        $this->assertSame('A: B: C:', $rendered);
    }

    #[Test]
    public function it_replaces_repeated_tokens(): void
    {
        $body = '{{contact_first_name}} {{contact_first_name}} {{contact_first_name}}';

        $rendered = $this->renderer->render($body, [
            'contact_first_name' => 'Hi',
        ]);

        $this->assertSame('Hi Hi Hi', $rendered);
    }
}
