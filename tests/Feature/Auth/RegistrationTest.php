<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function register_route_is_not_registered(): void
    {
        // Fortify only binds the /register routes when Features::registration()
        // is enabled. PRD §7.6 disables registration, so both GET and POST
        // must return 404 — the routes simply do not exist.
        $this->get('/register')->assertNotFound();

        $this->post('/register', [
            'name' => 'Trespasser',
            'email' => 'trespasser@example.test',
            'password' => 'some-password',
            'password_confirmation' => 'some-password',
        ])->assertNotFound();
    }
}
