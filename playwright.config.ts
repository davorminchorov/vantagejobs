/**
 * Shared config for the dev-only Playwright specs under tests/responsive,
 * tests/a11y, and tests/browser. @playwright/test and @axe-core/playwright
 * are devDependencies; run the specs with `pnpm exec playwright test ...`
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
    // capture-auth-state.ts doesn't follow the *.spec.ts naming, so the
    // default testMatch skips it ("No tests found"). List it explicitly.
    testMatch: ['**/*.spec.ts', '**/tests/browser/capture-auth-state.ts'],
    use: {
        storageState: process.env.STORAGE_STATE,
    },
});
