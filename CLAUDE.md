# Local execution

This project runs in Docker via `spin`. Every `php`, `artisan`, `composer`, `vendor/bin/*` (including `pint`), and test command MUST be prefixed with `spin run php` — never invoke them directly on the host. Examples:

```bash
spin run php artisan make:model Post --no-interaction
spin run php artisan route:list
spin run php artisan tinker --execute 'User::count();'
spin run php ./vendor/bin/pint --dirty --format agent
spin run php artisan test --compact --parallel --recreate-databases
```

When the Boost rules below show `php artisan ...` or `vendor/bin/pint ...`, mentally rewrite them with the `spin run php` prefix before running.

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.5
- laravel/framework (LARAVEL) - v13
- laravel/horizon (HORIZON) - v5
- laravel/octane (OCTANE) - v2
- laravel/prompts (PROMPTS) - v0
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- phpunit/phpunit (PHPUNIT) - v12
- tailwindcss (TAILWINDCSS) - v4

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.
- To check environment variables, read the `.env` file directly.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== octane/core rules ===

# Octane

- Octane boots the application once and reuses it across requests, so singletons persist between requests.
- The Laravel container's `scoped` method may be used as a safe alternative to `singleton`.
- Never inject the container, request, or config repository into a singleton's constructor; use a resolver closure or `bind()` instead:

```php
// Bad
$this->app->singleton(Service::class, fn (Application $app) => new Service($app['request']));

// Good
$this->app->singleton(Service::class, fn () => new Service(fn () => request()));
```

- Never append to static properties, as they accumulate in memory across requests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This application uses PHPUnit for testing. All tests must be written as PHPUnit classes. Use `php artisan make:test --phpunit {name}` to create a new test.
- If you see a test using "Pest", convert it to PHPUnit.
- Every time a test has been updated, run that singular test.
- When the tests relating to your feature are passing, ask the user if they would like to also run the entire test suite to make sure everything is still passing.
- Tests should cover all happy paths, failure paths, and edge cases.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files; these are core to the application.

## Running Tests

- Run the minimal number of tests, using an appropriate filter, before finalizing.
- To run all tests: `php artisan test --compact`.
- To run all tests in a file: `php artisan test --compact tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `php artisan test --compact --filter=testName` (recommended after making a change to a related file).

=== serversideup/spin rules ===

# Spin — Docker Workflow for Laravel

Spin is a lightweight CLI that wraps Docker Compose (development) and Docker Swarm (production) so you can replicate any environment on any machine.

## Key facts

- Spin follows Docker Compose syntax exactly — `spin up` runs `docker compose up`, `spin run` runs `docker compose run`, etc.
- Projects use a **Docker Compose overrides** pattern: a base `docker-compose.yml` merged with `docker-compose.$SPIN_ENV.yml`. `SPIN_ENV` defaults to `dev` (→ `docker-compose.dev.yml`). Common values: `dev`, `ci`, `prod`.
- Laravel projects use `serversideup/php` Docker images (fpm-nginx, fpm-apache, frankenphp, cli).
- The `.infrastructure/` folder stores configuration files (`conf/`) and gitignored volume data (`volume_data/`).
- In Docker, services connect via container name as hostname (`DB_HOST=mysql`, `REDIS_HOST=redis`), not `localhost`.

## Essential commands

| Command | Purpose |
|---------|---------|
| `spin up` | Start development environment (foreground) |
| `spin up --build` | Start and rebuild containers (recommended default if custom Dockerfile is used) |
| `spin exec <service> <cmd>` | Run a command in a running container (reuses live container, near-instant) |
| `spin run <service> <cmd>` | Run a one-off command in a new container (use when the stack is not running) |
| `spin deploy <env>` | Deploy to a provisioned server |
| `spin provision` | Provision and configure servers |

### `spin exec` vs `spin run`

- **`spin exec`** — use when the stack is running. Reuses the live container. This is the right default for `artisan`, `composer`, `npm`, tests, and ad-hoc commands during development.
- **`spin run`** — use when the stack is not running, or when a fresh container with different env vars or isolated state is needed. Creates (and removes) a new container each invocation.

### The `-T` flag (disable pseudo-TTY)

Compose auto-detects TTY by default, so interactive use from a terminal usually works without `-T`. **In AI agent, CI, and other subprocess contexts**, that auto-detection can misfire — Compose sees a TTY on stdin while downstream output is being captured, which can cause hangs, ANSI-garbled output, or prompts with nowhere to respond.

Pass `-T` as a defensive default when running commands from an AI agent, CI pipeline, or any wrapper script:

```bash
./vendor/bin/spin exec -T php php artisan test
./vendor/bin/spin run  -T php composer install
```

Omit `-T` when interactivity is actually needed (`artisan tinker` without `--execute`, interactive `make:*` prompts, `spin exec php bash`).

### Running Laravel tests

Prefer the **already-running dev stack** — it's faster than spinning up a parallel CI stack:

```bash
./vendor/bin/spin exec -T php php artisan test
./vendor/bin/spin exec -T php php artisan test --filter=ExampleTest
```

`php artisan test` works for both PHPUnit and Pest. If the project exposes a `composer test` script, prefer that via `spin exec -T php composer test`.

**Before assuming the dev stack is enough, inspect the test config** (`phpunit.xml`, `phpunit.xml.dist`, or `phpunit.dist.xml`):

- If `<php>` overrides `DB_CONNECTION` to `sqlite` with `DB_DATABASE=:memory:` (and `CACHE_STORE`/`QUEUE_CONNECTION`/`SESSION_DRIVER` set to `array`/`sync`), tests are self-contained — the dev stack is plenty.
- If the test config uses the real database/cache services, the dev stack usually still works. Reach for `SPIN_ENV=ci` only when CI parity is needed (reproducing a CI-only failure, matching exact service versions, dry-running the pipeline). See the **spin-laravel-development** skill's `TESTING.md` for the full CI-parity workflow.

## When to activate the full skill

Activate the **spin-laravel-development** skill for tasks involving:
- Docker Compose configuration or Dockerfile changes
- `serversideup/php` image settings
- Adding Laravel services (databases, queues, Horizon, Reverb)
- Running tests with CI parity (`SPIN_ENV=ci`)
- Running multiple Compose environments in parallel
- Server provisioning, deployment, or troubleshooting containerized environments

## Laravel Boost MCP setup

Since Spin runs PHP inside Docker (not on the host), Laravel Boost needs special configuration to start its MCP server. Spin ships `spin-mcp-wait.sh` which retries until Docker is ready and filters stdout for clean JSON-RPC communication.

Add these to your `.env` file:

```
BOOST_PHP_EXECUTABLE_PATH="./vendor/bin/spin-mcp-wait.sh ./vendor/bin/spin run -T php php"
BOOST_COMPOSER_EXECUTABLE_PATH="./vendor/bin/spin run php composer"
BOOST_NPM_EXECUTABLE_PATH="./vendor/bin/spin run node npm"
```

Then run:

```
spin run php php artisan boost:install
```

**IMPORTANT:** `spin-mcp-wait.sh` is exclusively for MCP server startup. NEVER use it to run commands. Always invoke `spin exec` (running stack) or `spin run` (stopped stack) directly.

If this error appears, drop `spin-mcp-wait.sh` and invoke `spin` directly:

```
Error: spin-mcp-wait.sh is only for starting the MCP server. Use 'spin' directly instead.
```

Correct:

```bash
./vendor/bin/spin exec -T php php artisan test
./vendor/bin/spin run -T php php artisan migrate
```

## Templates

- **Basic** (`spin new laravel`): Free open source template — <https://github.com/serversideup/spin-template-laravel-basic>
- **Pro** (`spin new laravel-pro`): Premium template with pre-configured services (Horizon, Reverb, databases, Vite, Mailpit, etc.) — <https://getspin.pro>

## Resources

- Spin docs: <https://serversideup.net/open-source/spin/docs>
- Spin docs (LLM-friendly): <https://serversideup.net/open-source/spin/llms.txt>
- serversideup/php docs: <https://serversideup.net/open-source/docker-php/docs>
- serversideup/php docs (LLM-friendly): <https://serversideup.net/open-source/docker-php/llms.txt>
- serversideup/php full docs (LLM-friendly): <https://serversideup.net/open-source/docker-php/llms-full.txt>
- serversideup/php environment variable reference: <https://serversideup.net/open-source/docker-php/docs/reference/environment-variable-specification>
- Spin Pro docs: <https://getspin.pro/docs>

</laravel-boost-guidelines>
