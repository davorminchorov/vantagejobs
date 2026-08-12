<?php

declare(strict_types=1);

namespace Tests\Unit\Scrapers\Support;

use App\Scrapers\Support\MojibakeRepairer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MojibakeRepairerTest extends TestCase
{
    #[Test]
    public function it_repairs_double_encoded_latin_characters(): void
    {
        $this->assertSame('México', MojibakeRepairer::repair('MÃ©xico'));
        $this->assertSame('Germain Hôtels', MojibakeRepairer::repair('Germain HÃ´tels'));
    }

    #[Test]
    public function it_repairs_double_encoded_smart_punctuation(): void
    {
        // "St George's Cares" mis-encoded via a right single quote (U+2019,
        // UTF-8 bytes 0xE2 0x80 0x99) run through the Latin-1 mis-decode:
        // each byte is reinterpreted as its own Latin-1 code point.
        $mojibake = "St George\u{00E2}\u{0080}\u{0099}s Cares";

        $this->assertSame("St George\u{2019}s Cares", MojibakeRepairer::repair($mojibake));
    }

    #[Test]
    public function it_leaves_plain_ascii_unchanged(): void
    {
        $this->assertSame('Acme Corp', MojibakeRepairer::repair('Acme Corp'));
        $this->assertSame('', MojibakeRepairer::repair(''));
    }

    #[Test]
    public function it_leaves_genuine_latin_script_text_unchanged(): void
    {
        $this->assertSame('Québec', MojibakeRepairer::repair('Québec'));
        $this->assertSame('São Paulo', MojibakeRepairer::repair('São Paulo'));
    }

    #[Test]
    public function it_leaves_upstream_truncated_sequences_unchanged(): void
    {
        $truncated = "Sales Development Representative Attribute\u{00E2}\u{0084}";

        $this->assertSame($truncated, MojibakeRepairer::repair($truncated));
    }

    #[Test]
    public function it_applies_repair_recursively_across_an_array_payload(): void
    {
        $payload = [
            'company' => 'Universia MÃ©xico',
            'location' => 'CoyoacÃ¡n, CDMX',
            'salary_min' => 150000,
            'tags' => ['remoto', 'programaciÃ³n'],
            'nested' => [
                'city' => 'MÃ©xico',
                'unaffected' => 'Québec',
            ],
        ];

        $repaired = MojibakeRepairer::repairArray($payload);

        $this->assertSame('Universia México', $repaired['company']);
        $this->assertSame('Coyoacán, CDMX', $repaired['location']);
        $this->assertSame(150000, $repaired['salary_min']);
        $this->assertSame(['remoto', 'programación'], $repaired['tags']);
        $this->assertSame('México', $repaired['nested']['city']);
        $this->assertSame('Québec', $repaired['nested']['unaffected']);
    }
}
