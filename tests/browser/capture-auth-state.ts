/**
 * Auth-state capture (CI/dev-only helper).
 *
 * Logs in once and saves the authenticated browser context to STORAGE_STATE
 * so tests/responsive/viewport.spec.ts and tests/a11y/axe.spec.ts can start
 * already signed in. This is not an assertion suite — it exists purely to
 * write a session to disk.
 *
 * This file is intentionally NOT part of the PHPUnit suite and is NOT wired
 * into the same job as the specs it feeds. Playwright is not a project
 * dependency; run it ad hoc against a running app:
 *
 *   pnpm dlx playwright install chromium
 *   APP_URL=http://localhost:8080 \
 *   LOGIN_EMAIL=admin@vantagejobs.test \
 *   LOGIN_PASSWORD=... \
 *   STORAGE_STATE=/tmp/vj-auth.json \
 *     pnpm dlx playwright test tests/browser/capture-auth-state.ts
 *
 * Authentication: this app uses session cookies (Fortify). The seeded user
 * must not have two-factor confirmed, or Fortify will redirect to the 2FA
 * challenge instead of /dashboard and this script will time out.
 */
import { test } from '@playwright/test';

const BASE_URL = process.env.APP_URL ?? 'http://localhost';
const EMAIL = process.env.LOGIN_EMAIL ?? '';
const PASSWORD = process.env.LOGIN_PASSWORD ?? '';
const STORAGE_STATE = process.env.STORAGE_STATE ?? 'tests/browser/.auth.json';

// The shared playwright.config.ts seeds every context from STORAGE_STATE,
// but this test is the one that creates that file; start from a blank
// context so the load of a not-yet-written path can't fail the capture.
test.use({ storageState: { cookies: [], origins: [] } });

test('capture an authenticated storage state', async ({ page }) => {
    if (!EMAIL || !PASSWORD) {
        throw new Error('LOGIN_EMAIL and LOGIN_PASSWORD must both be set');
    }

    await page.goto(`${BASE_URL}/login`, { waitUntil: 'networkidle' });

    await page.getByLabel('Email').fill(EMAIL);
    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();

    await page.waitForURL(`${BASE_URL}/dashboard`, { waitUntil: 'networkidle' });

    await page.context().storageState({ path: STORAGE_STATE });
});
