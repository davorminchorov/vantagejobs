/**
 * Shared config for the dev-only Playwright specs under tests/responsive,
 * tests/a11y, and tests/browser. Neither Playwright nor axe-core is a
 * project dependency; run these ad hoc with `pnpm dlx playwright test ...`
 * (see each spec's own docblock for the exact invocation and env vars).
 *
 * STORAGE_STATE, when set, seeds every test's browser context so the
 * priority pages start already signed in. Capture one with
 * tests/browser/capture-auth-state.ts. Without it, the specs still run —
 * each one detects the redirect to /login and skips its authenticated
 * assertions rather than failing.
 */
import { defineConfig } from '@playwright/test';

export default defineConfig({
    use: {
        storageState: process.env.STORAGE_STATE,
    },
});
