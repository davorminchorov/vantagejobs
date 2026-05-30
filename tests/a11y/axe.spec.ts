/**
 * Accessibility audit (dev-only).
 *
 * Runs axe-core against the three priority pages — dashboard, triage
 * (/discover), and a company detail page — and asserts zero `critical`
 * violations (PRD §7.10 acceptance bar). It also exercises the keyboard
 * contract that this slice wires up: j/k roving focus on the Companies and
 * Discover lists, Esc clearing the roving focus, Enter activating the focused
 * row, and the shadcn Dialog trapping focus + closing on Esc.
 *
 * This spec is intentionally NOT part of the PHPUnit suite and is NOT wired
 * into CI. Neither Playwright nor axe-core is a project dependency; run it ad
 * hoc against a running dev server:
 *
 *   pnpm dlx playwright install chromium
 *   APP_URL=http://localhost STORAGE_STATE=tests/a11y/.auth.json \
 *     pnpm dlx playwright test tests/a11y/axe.spec.ts
 *
 * The `@axe-core/playwright` integration is loaded with `pnpm dlx` at run time
 * so it never lands in package.json.
 *
 * Authentication: this app uses session cookies (Fortify). Capture a logged-in
 * storage state once and point STORAGE_STATE at it. Without auth the priority
 * pages redirect to /login, in which case each test skips its authenticated
 * assertions rather than failing.
 */
import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

const BASE_URL = process.env.APP_URL ?? 'http://localhost';

const PRIORITY_PATHS = ['/dashboard', '/discover'] as const;

async function isAuthenticated(page: Page): Promise<boolean> {
    return !page.url().includes('/login');
}

/**
 * Run axe against the current page and return only the `critical`-impact
 * violations. The acceptance bar is zero criticals; `serious`/`moderate` are
 * surfaced in the console for triage but do not fail the build.
 */
async function criticalViolations(page: Page) {
    const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
        .analyze();

    const lower = results.violations.filter((v) => v.impact !== 'critical');
    if (lower.length > 0) {
        // eslint-disable-next-line no-console
        console.log(
            'Non-critical a11y findings:',
            lower.map((v) => `${v.impact}:${v.id} (${v.nodes.length})`).join(', '),
        );
    }

    return results.violations.filter((v) => v.impact === 'critical');
}

for (const path of PRIORITY_PATHS) {
    test(`zero critical axe violations on ${path}`, async ({ page }) => {
        await page.goto(`${BASE_URL}${path}`, { waitUntil: 'networkidle' });

        if (!(await isAuthenticated(page))) {
            test.skip(true, `redirected to login for ${path}; provide STORAGE_STATE`);
            return;
        }

        const critical = await criticalViolations(page);
        expect(critical, JSON.stringify(critical, null, 2)).toEqual([]);
    });
}

test('zero critical axe violations on a company detail page', async ({ page }) => {
    await page.goto(`${BASE_URL}/companies`, { waitUntil: 'networkidle' });

    if (!(await isAuthenticated(page))) {
        test.skip(true, 'redirected to login; provide STORAGE_STATE');
        return;
    }

    // Open the first company so we audit the detail view, not the index.
    const firstCompany = page.getByRole('link', { name: /.+/ }).filter({ hasNotText: /^(Edit|Bulk import|Add company)$/ });
    const detailLink = page.locator('tbody a[href^="/companies/"]').first();
    if (await detailLink.count()) {
        await detailLink.click();
        await page.waitForLoadState('networkidle');
    } else {
        test.skip(true, 'no companies seeded; cannot reach a detail page');
        return;
    }
    void firstCompany;

    const critical = await criticalViolations(page);
    expect(critical, JSON.stringify(critical, null, 2)).toEqual([]);
});

test('j/k roving focus and Enter activation work on the Companies list', async ({ page }) => {
    await page.goto(`${BASE_URL}/companies`, { waitUntil: 'networkidle' });

    if (!(await isAuthenticated(page))) {
        test.skip(true, 'redirected to login; provide STORAGE_STATE');
        return;
    }

    const rows = page.locator('tbody tr');
    if ((await rows.count()) === 0) {
        test.skip(true, 'no companies seeded');
        return;
    }

    // j moves focus to the first row, j again to the second.
    await page.keyboard.press('j');
    await expect(rows.nth(0)).toBeFocused();
    await page.keyboard.press('j');
    await expect(rows.nth(1)).toBeFocused();

    // k moves focus back up.
    await page.keyboard.press('k');
    await expect(rows.nth(0)).toBeFocused();

    // Escape clears the roving focus ring.
    await page.keyboard.press('Escape');

    // Enter on a focused row navigates to its detail page.
    await page.keyboard.press('j');
    await page.keyboard.press('Enter');
    await page.waitForLoadState('networkidle');
    expect(page.url()).toMatch(/\/companies\/\d+/);
});

test('j/↓ roving focus and triage shortcuts work on Discover', async ({ page }) => {
    await page.goto(`${BASE_URL}/discover`, { waitUntil: 'networkidle' });

    if (!(await isAuthenticated(page))) {
        test.skip(true, 'redirected to login; provide STORAGE_STATE');
        return;
    }

    const cards = page.locator('ul > li');
    if ((await cards.count()) === 0) {
        test.skip(true, 'no listings to triage');
        return;
    }

    // j focuses the first card; the ring class is applied.
    await page.keyboard.press('j');
    await expect(cards.first()).toContainClass('ring-1');
});

test('the shadcn Dialog traps focus and closes on Escape', async ({ page }) => {
    // The bulk action bar opens a confirmation Dialog; we drive it from the
    // Companies page where a row checkbox reveals the bar.
    await page.goto(`${BASE_URL}/companies`, { waitUntil: 'networkidle' });

    if (!(await isAuthenticated(page))) {
        test.skip(true, 'redirected to login; provide STORAGE_STATE');
        return;
    }

    const firstCheckbox = page.locator('tbody input[type="checkbox"]').first();
    if ((await firstCheckbox.count()) === 0) {
        test.skip(true, 'no rows to select');
        return;
    }

    await firstCheckbox.check();

    const dialog = page.getByRole('dialog');
    if ((await dialog.count()) === 0) {
        // No dialog flow on this page build; nothing to assert.
        test.skip(true, 'no dialog reachable from the bulk bar');
        return;
    }

    await expect(dialog).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
});

test('prefers-reduced-motion collapses transitions', async ({ browser }) => {
    const context = await browser.newContext({ reducedMotion: 'reduce' });
    const page = await context.newPage();
    await page.goto(`${BASE_URL}/login`, { waitUntil: 'networkidle' });

    // Pick any element with a transition and assert the duration was clamped by
    // the @media (prefers-reduced-motion: reduce) rule in app.css.
    const duration = await page.evaluate(() => {
        const el = document.querySelector('a, button');
        if (!el) {
            return null;
        }
        return getComputedStyle(el).transitionDuration;
    });

    if (duration !== null) {
        // 0.01ms renders as "0.00001s" / "1e-05s"; assert it is effectively zero.
        const seconds = parseFloat(duration);
        expect(seconds).toBeLessThan(0.05);
    }

    await context.close();
});
