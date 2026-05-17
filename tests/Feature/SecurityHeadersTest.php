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

        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertNotEmpty($csp);
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
    }

    #[Test]
    public function the_script_src_directive_uses_a_nonce_and_no_unsafe_keywords(): void
    {
        $response = $this->get('/');

        $csp = (string) $response->headers->get('Content-Security-Policy');

        $scriptSrc = $this->extractDirective($csp, 'script-src');

        $this->assertNotNull($scriptSrc);
        $this->assertStringContainsString("'nonce-", $scriptSrc);
        $this->assertStringNotContainsString("'unsafe-inline'", $scriptSrc);
        $this->assertStringNotContainsString("'unsafe-eval'", $scriptSrc);
        $this->assertStringNotContainsString("'unsafe-eval'", $csp);
    }

    #[Test]
    public function a_unique_nonce_is_generated_per_request(): void
    {
        $first = (string) $this->get('/')->headers->get('Content-Security-Policy');
        $second = (string) $this->get('/')->headers->get('Content-Security-Policy');

        $firstNonce = $this->extractNonce($first);
        $secondNonce = $this->extractNonce($second);

        $this->assertNotNull($firstNonce);
        $this->assertNotNull($secondNonce);
        $this->assertNotSame($firstNonce, $secondNonce);
    }

    #[Test]
    public function dev_mode_allows_vite_websocket_in_connect_src(): void
    {
        $this->app->detectEnvironment(fn () => 'local');

        $csp = (string) $this->get('/')->headers->get('Content-Security-Policy');

        $connectSrc = $this->extractDirective($csp, 'connect-src');

        $this->assertNotNull($connectSrc);
        $this->assertMatchesRegularExpression('/wss?:\/\//', $connectSrc);
    }

    #[Test]
    public function hsts_is_set_only_over_https(): void
    {
        $this->get('http://localhost/')->assertHeaderMissing('Strict-Transport-Security');

        $this->get('https://localhost/')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    private function extractDirective(string $csp, string $name): ?string
    {
        foreach (explode(';', $csp) as $directive) {
            $directive = trim($directive);
            if (str_starts_with($directive, $name.' ')) {
                return $directive;
            }
        }

        return null;
    }

    private function extractNonce(string $csp): ?string
    {
        if (preg_match("/'nonce-([^']+)'/", $csp, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
