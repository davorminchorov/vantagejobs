<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_root_response_carries_the_baseline_security_headers(): void
    {
        $response = $this->get('/');

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    #[Test]
    public function a_content_security_policy_is_set(): void
    {
        $response = $this->get('/');

        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertNotNull($csp);
        $this->assertStringContainsString("default-src 'self'", (string) $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $csp);
    }

    #[Test]
    public function hsts_is_set_only_over_https(): void
    {
        $this->get('http://localhost/')->assertHeaderMissing('Strict-Transport-Security');

        $this->get('https://localhost/')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }
}
