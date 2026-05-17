# Phase 0 Notes

**Date:** May 2026
**Ship criterion (PRD §10):** Log in, log out, change password, enable 2FA, see a placeholder dashboard. Platform deployed to Laravel Cloud, HTTPS, public-internet-safe.
**Status:** Mechanically complete on `main`. Production deploy (Wave F / step 12) **deferred** at the user's call. Locally, the ship criterion is met end to end.

---

## What shipped

- Laravel 13 + PHP 8.5 + Postgres 18 scaffold adapted onto the pre-existing Spin Pro template (Wave A reused it rather than starting from `composer create-project`).
- Inertia v3 + React 19 + TypeScript + Tailwind v4 + shadcn/ui baseline. One Inertia entry (`resources/js/app.tsx`), `@/` path alias to `resources/js/`, shadcn primitives generated via `components.json` (no need to re-init).
- Hand-rolled row-level multi-tenancy from day one: `tenants` table, `Tenant` model, `TenantContext` service (singleton, with `withTenant()` push/pop), `BelongsToTenant` trait that adds a global Eloquent scope and a `creating` observer that auto-fills `tenant_id` (throws `RequiresTenantContextException` if no current tenant). Resolved per request via `ResolveTenantContext` middleware. Single active tenant in v1.
- Core schema with `tenant_id` everywhere: `tenants`, `users` (+ FK), `sources`, `companies`, `roles`, `events`, `notes`, `audit_logs`. Fortify's `two_factor_*` columns and `passkeys` migration kept (passkeys live in the schema but unused in v1 UI).
- Fortify wired headless: email + password + TOTP 2FA, no public `/register` route (removed `Features::registration()`), rate limiters `login` (`perMinutes(15, 5)` keyed by email+IP), `two-factor`, `password-reset` (`perHour(3)`). Login/Forgot/Reset/Confirm/TwoFactorChallenge Inertia pages.
- Settings shell: `/settings/{profile,password,two-factor,sessions}` with a `SettingsLayout` sidebar. Profile and password use Fortify's PUT endpoints; 2FA enroll/confirm/disable + recovery codes wired against Fortify's JSON endpoints; sessions exposes only "Sign out everywhere" (see decision below).
- AuditLog listener (`app/Listeners/Auth/RecordAuthEvent.php`) handles 13 framework + Fortify auth events. Auto-discovered via Laravel 11+ convention; explicit `Event::listen()` registrations caused double-dispatch and were removed (documented inline).
- Seeders chained tenant → admin user → 10 starter companies (real Greenhouse/Lever/Ashby slugs). Recovery commands: `user:reset-password`, `user:disable-2fa`, `tenant:create`.
- Public `/up` (Laravel's built-in liveness, no DB) + auth-required `/health` (DB ping JSON, 503 on failure).
- `SecurityHeaders` middleware (X-Frame-Options DENY, X-Content-Type-Options, Referrer-Policy, conditional HSTS over HTTPS, baseline CSP).
- Dashboard renders the seeded companies for the current tenant with an empty-state CTA when the watch list is empty.

## Deviations from PRD v3 (recorded inline in the relevant docs)

- **Saloon over bare Guzzle.** PRD §9 originally said "Guzzle via `Http::pool()`." Replaced with `saloonphp/saloon` + `saloonphp/laravel-plugin` after the user's call. With 10+ source integrations planned across Phase 1-6, hand-rolled Guzzle pools end up reinventing what Saloon provides. PRD and ARCHITECTURE updated.
- **Redis in production for sessions only.** PRD originally said "no Redis in v1." Reversed for the session driver (`SESSION_DRIVER=redis` in both dev and prod). Cache stays on the database driver; queues stay off. Cost delta ~$5/mo on Laravel Cloud. Documented in PRD §9 and ARCHITECTURE.
- **Local stack runs Octane + Horizon + Redis** (Spin Pro template defaults), **production runs FrankenPHP classic mode + no Horizon**. Accepted dev/prod runtime divergence in exchange for keeping the scaffolded local stack intact. Discipline note in PRD §9: any singleton/static state must reset cleanly per request, since prod will reset for us but local won't catch the leak.
- **Active-session listing deferred to Phase 6+.** Listing individual Redis sessions via SCAN is fragile and doesn't earn its keep for a personal tool. Only "Sign out everywhere" is wired in v1 (uses `Auth::logoutOtherDevices`, driver-agnostic).
- **No `/register` route at all** (not even returning 404 from a custom handler). `Features::registration()` is removed from `config/fortify.php`, which means Fortify never binds the route. Tests confirm.
- **Custom `cloud.yaml` / production env list not authored** — Wave F deferred.

## Decisions worth carrying into Phase 1

- **Tenant context is mandatory for tenant-scoped writes.** The `BelongsToTenant` observer throws if `TenantContext::current()` is null at insert time. Every scrape command, signal job, and seeder that touches Company/Role/Event/Note/Source/User must either accept a `--tenant=` option and call `app(TenantContext::class)->setCurrent($tenant)` at the top, or wrap each iteration in `$context->withTenant($tenant, fn () => …)`. The `BelongsToTenantScope` is a no-op when context is unset (so migrations and `/up` don't crash), but inserts will always fail loudly. This is intentional.
- **AuditLog deliberately does NOT use `BelongsToTenant`.** Failed-login and lockout events fire before tenant context can be resolved (no user, no tenant). Audit log queries that need tenant scoping have to filter explicitly. Documented on `App\Models\AuditLog`.
- **HandleInertiaRequests shares `auth.user`, `tenant: { id, name, slug, timezone } | null`, and `flash`.** The frontend `PageProps<T>` type in `resources/js/types/index.ts` reflects this. Phase 1 should keep that contract stable (add new shared props, do not rename existing ones).
- **Test discipline.** PHPUnit with `#[Test]` attributes. snake_case test method names (Pint's `php_unit_method_casing` rule). `RefreshDatabase` + `RefreshDatabase` setUp pattern: create tenant via factory, `app(TenantContext::class)->setCurrent($tenant)`, then create scoped models. See `tests/Unit/Tenancy/BelongsToTenantTest.php` and `tests/Feature/Schema/ModelTenancyTest.php` for the patterns.
- **All commands run through Spin.** `spin exec -T <service> <cmd>` when the stack is up; `spin run -T <service> <cmd>` for one-offs. Never host-side. Codified in project CLAUDE.md and the README quick start.
- **Pint with `--format agent`** for all PHP. PHPUnit method casing is enforced (snake_case), as is `class_attributes_separation` (blank line between use-traits), `fully_qualified_strict_types` in bootstrap files, `new_with_parentheses`, and `self_static_accessor`.

## Open follow-ups (deferred, not blockers)

- **Wave F (step 12): Laravel Cloud deploy.** Scaffold `cloud.yaml`, register the production custom domain via `cloud domains:add`, set the secrets list (APP_KEY, INITIAL_TENANT_*, INITIAL_USER_*, mail driver, B2 backup keys, GitHub PAT for Phase 4), and wire the Cloud scheduled task (`* * * * * php artisan schedule:run`).
- **CSP tightening.** Current baseline allows `'unsafe-inline'` and `'unsafe-eval'` on `script-src` to accommodate Vite HMR + Inertia bootstrap. Investigate nonce-based CSP for production builds; loosen only what Vite/Inertia actually need.
- **Vite bundle is 540 KB minified.** Single warning during build. Code-split via dynamic `import()` in Phase 1 once we know which pages are actually heavy (Triage queue, Pipeline kanban are likely candidates).
- **ESLint + lint script.** `npm run lint` is referenced in the README but ESLint is not installed. Add when frontend churn justifies the cost.
- **Session listing (PRD §7.6).** Listing individual Redis sessions with device/IP/last-activity per the PRD is deferred. Either add Redis SCAN-based enumeration in Phase 6+ or accept the simpler "Sign out everywhere" surface long-term.
- **`/.mcp.json` ships a `uidotsh` bearer token in plaintext.** Repo is private and the token is project-scoped per the commit message, but if the repo ever goes public the token must rotate.
- **`UserFactory` auto-creates a Tenant when context is unset** (for test ergonomics). This is convenient but slightly clever — a future bug could mask a missing `setCurrent()` call. Watch for it.
- **`Auth::logoutOtherDevices` test contract.** The `SessionsTest::destroy_others_succeeds_with_correct_password` test asserts the redirect + flash contract rather than `remember_token` rotation, because rotation only triggers against a real session store. Acceptable for the personal tool; revisit if a regression slips through.

## Verification snapshot at the end of Phase 0

- **PHP test suite:** 74 tests, 267 assertions, parallel via ParaTest (10 processes), 13.7s.
- **TypeScript:** `npx tsc --noEmit` clean.
- **Vite build:** green; one pre-existing `chunk > 500 KB` warning.
- **Pint:** `{"tool":"pint","result":"passed"}`.
- **Migrations:** 14 applied cleanly via `migrate:fresh`. Order: users → cache → jobs → 2FA → passkeys → tenants → extend-users-for-tenancy → sources → companies → roles → events → notes → audit_logs. Sessions live in Redis (no migration).

## File map for orientation

```
app/
├── Actions/Fortify/                          published & hardened (no CreateNewUser)
├── Console/Commands/                         user:reset-password, user:disable-2fa, tenant:create
├── Http/
│   ├── Controllers/                          DashboardController, HealthController, Settings/*
│   └── Middleware/                           HandleInertiaRequests, ResolveTenantContext, SecurityHeaders
├── Listeners/Auth/RecordAuthEvent.php        13 auth events → audit_logs
├── Models/                                   Source, Company, Role, Event, Note, AuditLog, User
├── Providers/                                AppServiceProvider, FortifyServiceProvider, TenancyServiceProvider
└── Tenancy/                                  Tenant, TenantContext, BelongsToTenant, BelongsToTenantScope,
                                              RequiresTenantContextException, ResolveTenantContext (mw lives here too)

database/
├── factories/                                Tenant, User, Source, Company, Role, Event, Note, AuditLog
├── migrations/                               14 files (see Verification snapshot above)
└── seeders/                                  CreateInitialTenantSeeder, CreateAdminUserSeeder,
                                              ExampleCompaniesSeeder, DatabaseSeeder (chains all three)

resources/js/
├── Layouts/                                  AuthenticatedLayout, GuestLayout, SettingsLayout
├── Pages/                                    Welcome, Dashboard, Auth/{Login,ForgotPassword,ResetPassword,
│                                             ConfirmPassword,TwoFactorChallenge}, Settings/{Profile,Password,
│                                             TwoFactor,Sessions}
├── components/ui/                            button, card, dropdown-menu, avatar, sheet, separator, input, label
├── hooks/use-appearance.ts                   light/dark/system mode, persisted to localStorage
├── lib/utils.ts                              cn() helper
└── types/index.ts                            User, SharedAuth, SharedFlash, SharedTenant, PageProps<T>

tests/
├── Feature/
│   ├── Auth/                                 LoginTest, RegistrationTest, PasswordResetTest, TwoFactorTest,
│   │                                         AuditLogListenerTest
│   ├── Commands/                             UserResetPassword, UserDisableTwoFactor, TenantCreate
│   ├── Seeders/SeederIdempotencyTest         (4)
│   ├── Schema/ModelTenancyTest               (10) — proves BelongsToTenant on real models
│   ├── Settings/                             SettingsRoutes, Sessions
│   ├── DashboardTest, HealthCheckTest, SecurityHeadersTest
└── Unit/Tenancy/                             TenantContextTest, BelongsToTenantTest
```

## Commit trail (newest first)

```
26ac0ff Wave E: Settings UI, health endpoints, security headers, paratest
fa9224e Wave D Agent 3: Wire Fortify — disable registration, Inertia auth pages, rate limiters
2b19cf9 Wave D Agent 5: Seeders + offline recovery commands
39b5731 Wave D Agent 4: AuditLog listener for Fortify + framework auth events
9316939 Wave C: Phase 0 base schema + models with BelongsToTenant applied
4e32f3b Wave B: Pint fixes on tenancy primitives
4e2d557 Wave B Agent 2: Tenancy primitives
f3d3599 Wave B Agent 1: Auth + app layouts, dark-mode hook, shadcn primitives
73c85f2 Wave A: scaffold Inertia + React 19 + TS + Fortify + Saloon
72aba83 Rename .env.example defaults from laravel.* to vantagejobs
6a966e2 Bump PHP to ^8.5 and document the dev/prod runtime divergence
+ Boost/Skill housekeeping commits (de8ba65, 86492b8, 712e441, 5a13eab)
```

---

**Next phase:** Phase 1 (ATS Pipeline) per `docs/claude-code-phase-1-kickoff.md`. Recommended to start a fresh Claude Code session and paste the kickoff prompt; it explicitly reads this notes file plus the (now updated) PRD.
