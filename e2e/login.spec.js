// @ts-check
const { test, expect } = require('@playwright/test');
const { ADMIN_USER, ADMIN_PASS, MASTER_PASS } = require('./credentials.js');

/**
 * Login smoke test.
 *
 * Depends on install-wizard.spec.js having run first (workers: 1, alphabetical
 * order means install-wizard < login). It exercises the admin account that the
 * wizard created.
 *
 * sysPass requires the master password on the first login in a new browser
 * session — the #smpass field is shown by the server's AJAX response when it
 * determines the master key must be unlocked. This test handles that two-step
 * flow transparently.
 */
test.describe('Login', () => {
  test('logs in with the admin account and reaches the authenticated app', async ({ page }) => {
    await page.goto('/index.php?r=login');

    const loginBox = page.locator('#box-login');
    await expect(loginBox).toBeVisible();

    // Fill credentials and submit.
    await page.locator('#user').fill(ADMIN_USER);
    await page.locator('#pass').fill(ADMIN_PASS);

    // Wait for the login AJAX response and click together so we don't miss it.
    const [resp] = await Promise.all([
      page.waitForResponse(
        (r) => r.url().includes('login/login') && r.status() === 200,
        { timeout: 15_000 }
      ),
      page.locator('#btnLogin').click(),
    ]);

    const body = await resp.json().catch(() => null);

    // If the server requests the master password, the #smpass div is shown.
    // Fill it and re-submit.
    const masterPassDiv = page.locator('#smpass');
    const masterPassVisible = await masterPassDiv.isVisible();

    if (masterPassVisible || (body && body.status !== 'OK' && body.status !== 0)) {
      await expect(masterPassDiv).toBeVisible({ timeout: 5_000 });
      await page.locator('#mpass').fill(MASTER_PASS);

      await Promise.all([
        page.waitForResponse(
          (r) => r.url().includes('login/login') && r.status() === 200,
          { timeout: 15_000 }
        ),
        page.locator('#btnLogin').click(),
      ]);
    }

    // After a successful login sysPass redirects (full navigation) to the
    // accounts list. Wait for the login form to disappear from the DOM.
    await expect(loginBox).toBeHidden({ timeout: 15_000 });

    // The authenticated shell renders a header with the logged-in user's name.
    await expect(page.locator('#user-name')).toBeVisible({ timeout: 15_000 });
  });
});
