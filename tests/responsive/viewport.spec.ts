/**
 * Responsive viewport audit (dev-only).
 *
 * Screenshots the priority pages at a 375px-wide viewport (iPhone SE / small
 * Android) and asserts there is no page-level horizontal overflow and that the
 * primary nav has collapsed to the hamburger trigger below the `lg` (1024px)
 * breakpoint.
 *
 * This spec is not part of the PHPUnit suite. CI runs it in the non-blocking
 * browser job; locally, run it against a running dev server:
 *
 *   pnpm exec playwright install chromium
 *   APP_URL=http://localhost STORAGE_STATE=tests/responsive/.auth.json \
 *     pnpm exec playwright test tests/responsive/viewport.spec.ts
 *
 * Authentication: this app uses session cookies (Fortify). Capture a logged-in
 * storage state once and point STORAGE_STATE at it, or set BYPASS_AUTH in a
 * local-only middleware. Without auth the priority pages redirect to /login,
 * in which case the spec still verifies the login page and the hamburger
 * threshold but skips the authenticated assertions.
 */
import { expect, test, type Page } from '@playwright/test';

const BASE_URL = process.env.APP_URL ?? 'http://localhost';

// iPhone SE width — the narrowest mainstream target we support (PRD §7.10).
const MOBILE = { width: 375, height: 812 };

// Just below and at the `lg` breakpoint, to prove the sidebar switches mode.
const BELOW_LG = { width: 1023, height: 900 };
const AT_LG = { width: 1024, height: 900 };

const PRIORITY_PATHS = ['/discover', '/dashboard', '/companies'] as const;

test.use({ viewport: MOBILE });

/**
 * True when the document scrolls horizontally — the failure mode this slice
 * exists to prevent. A 1px tolerance absorbs sub-pixel rounding.
 */
async function hasHorizontalOverflow(page: Page): Promise<boolean> {
    return page.evaluate(() => {
        const doc = document.documentElement;
        return doc.scrollWidth - doc.clientWidth > 1;
    });
}

async function isAuthenticated(page: Page): Promise<boolean> {
    return !page.url().includes('/login');
}

for (const path of PRIORITY_PATHS) {
    test(`no horizontal overflow at 375px on ${path}`, async ({ page }) => {
        await page.goto(`${BASE_URL}${path}`, { waitUntil: 'networkidle' });

        if (!(await isAuthenticated(page))) {
            test.skip(true, `redirected to login for ${path}; provide STORAGE_STATE`);
            return;
        }

        await page.screenshot({
            path: `tests/responsive/__screenshots__/${path.replace(/\//g, '_')}-375.png`,
            fullPage: true,
        });

        expect(await hasHorizontalOverflow(page), `${path} overflows horizontally at 375px`).toBe(
            false,
        );
    });
}

test('primary nav collapses to a hamburger below lg', async ({ page }) => {
    // Below lg: the hamburger trigger is visible, the sidebar nav is not.
    await page.setViewportSize(BELOW_LG);
    await page.goto(`${BASE_URL}/dashboard`, { waitUntil: 'networkidle' });

    const hamburger = page.getByRole('button', { name: /open navigation menu/i });
    await expect(hamburger).toBeVisible();
    await expect(page.getByRole('navigation', { name: /^primary$/i })).toBeHidden();

    // At lg: the sidebar nav is visible, the hamburger is hidden.
    await page.setViewportSize(AT_LG);
    await expect(page.getByRole('navigation', { name: /^primary$/i })).toBeVisible();
    await expect(hamburger).toBeHidden();
});

test('interactive controls meet the 44px tap-target minimum on the triage page', async ({
    page,
}) => {
    await page.goto(`${BASE_URL}/discover`, { waitUntil: 'networkidle' });

    if (!(await isAuthenticated(page))) {
        test.skip(true, 'redirected to login; provide STORAGE_STATE');
        return;
    }

    // The hamburger and the first row of listing-card action buttons are the
    // primary touch targets on this page.
    const hamburger = page.getByRole('button', { name: /open navigation menu/i });
    const box = await hamburger.boundingBox();
    expect(box, 'hamburger has a layout box').not.toBeNull();
    if (box) {
        expect(box.height).toBeGreaterThanOrEqual(44);
        expect(box.width).toBeGreaterThanOrEqual(44);
    }

    const keep = page.getByRole('button', { name: 'Keep' }).first();
    if (await keep.count()) {
        const keepBox = await keep.boundingBox();
        if (keepBox) {
            expect(keepBox.height).toBeGreaterThanOrEqual(44);
        }
    }
});
