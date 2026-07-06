// @ts-check
const { test, expect } = require('@playwright/test');
const { execSync } = require('child_process');
const { ADMIN_PASS, MASTER_PASS } = require('./credentials.js');

/**
 * Reset the app to "not installed" state:
 *   1. Drop the syspass database (the installer re-creates it in standard mode)
 *   2. Remove config.xml so the app sees itself as not installed
 *   3. Clear the compiled DI container cache
 *
 * Uses docker compose from the host — the Playwright runner is on the host,
 * the PHP app and MariaDB live in Docker containers.
 *
 * WARNING: this destroys the syspass database and all installed configuration.
 * Never point this suite at an instance you want to keep.
 */
function resetApp() {
  const compose = 'docker compose -f docker-compose.yml';
  const cwd = `${__dirname}/..`;
  // Drop the DB entirely — standard-mode install creates it fresh.
  execSync(
    `${compose} exec -T db mariadb -uroot -psyspass -e "DROP DATABASE IF EXISTS syspass;"`,
    { cwd, stdio: 'pipe' }
  );
  execSync(
    `${compose} exec -T app rm -f /var/www/html/config/config.xml`,
    { cwd, stdio: 'pipe' }
  );
  execSync(
    `${compose} exec -T app sh -c 'rm -rf /var/www/html/var/cache/*'`,
    { cwd, stdio: 'pipe' }
  );
}

// ---------------------------------------------------------------------------
// Notes on password field interaction
//
// The app's JS (app-theme.js passwordDetect / app-main.min.js bindPassEncrypt)
// does two things to password inputs:
//
//   1. Renames the element's DOM id at init time (e.g. "adminpass" becomes
//      "adminpass-<uid>"). Use the unchanging name= attribute to locate them.
//
//   2. PKI-encrypts the field value on blur (RSA, synchronous). When Playwright
//      moves its pointer to click a button the previously focused field blurs
//      mid-mousedown, which can cause the wizard's click handler to see
//      half-updated state. Explicitly blurring the repeat field before clicking
//      Next / Install keeps the state consistent.
// ---------------------------------------------------------------------------

test.describe('Install wizard', () => {
  test.beforeAll(() => {
    resetApp();
  });

  test('Next button is greyed until the admin password fields are filled', async ({ page }) => {
    // Navigate to the wizard — install pages are in PARTIAL_INIT and always
    // accessible (this test only exercises button-state UI, not re-install).
    await page.goto('/index.php?r=install/index');
    await expect(page.locator('#frmInstall')).toBeVisible();

    const btnNext = page.locator('#btnNext');

    // Step 1 → 2
    await btnNext.click();
    await expect(page.locator('#requirements')).toBeVisible();

    // Step 2 → 3 (requirements must all pass)
    await expect(page.locator('#requirements .req-fail')).toHaveCount(0);
    await btnNext.click();
    await expect(page.locator('#databaseField')).toBeVisible();

    // Step 3 → 4: fill required DB fields then advance.
    await page.locator('#dbhost').fill('db');
    await page.locator('[name="dbpass"]').fill('syspass');
    await btnNext.click();
    await expect(page.locator('#adminaccount')).toBeVisible();

    // Button should not have the accent class yet (no passwords filled).
    await expect(btnNext).not.toHaveClass(/mdl-button--accent/);

    // Fill only the first password — button stays grey (repeat still empty).
    await page.locator('[name="adminpass"]').fill('SomePass1!');
    await expect(btnNext).not.toHaveClass(/mdl-button--accent/);

    // Fill the repeat — both fields are now filled, button becomes accent.
    await page.locator('[name="adminpassr"]').fill('SomePass1!');
    await expect(btnNext).toHaveClass(/mdl-button--accent/);

    // Clear the first field — button goes grey again.
    await page.locator('[name="adminpass"]').fill('');
    await expect(btnNext).not.toHaveClass(/mdl-button--accent/);
  });

  test('completes the 5-step install wizard and shows the success screen', async ({ page }) => {
    // ── Open the install wizard ─────────────────────────────────────────────
    await page.goto('/index.php?r=install/index');

    // The wizard form must be present — not a login redirect.
    await expect(page.locator('#frmInstall')).toBeVisible();

    // Five step-circles in the stepper.
    await expect(page.locator('.step-circle')).toHaveCount(5);

    // ── Step 1 — Welcome ───────────────────────────────────────────────────
    // The welcome section is the active panel on first load.
    await expect(page.locator('#welcome')).toBeVisible();

    // The language picker is enhanced by selectize, which hides the native
    // <select id="sel-sitelang"> and creates a custom .selectize-control.
    await expect(page.locator('#welcome .selectize-control')).toBeVisible();

    // The Next button is always enabled on the welcome step (no required fields).
    const btnNext = page.locator('#btnNext');
    await expect(btnNext).toBeVisible();
    await btnNext.click();

    // ── Step 2 — Requirements ──────────────────────────────────────────────
    const requirementsSection = page.locator('#requirements');
    await expect(requirementsSection).toBeVisible();

    // The Docker container satisfies all required PHP extensions.
    await expect(requirementsSection.locator('.req-fail')).toHaveCount(0);

    await btnNext.click();

    // ── Step 3 — DB Configuration ──────────────────────────────────────────
    const dbSection = page.locator('#databaseField');
    await expect(dbSection).toBeVisible();

    // Default mode is Standard (hostingmode=0): the installer creates the DB.
    // Set the DB host to the Docker service name and supply the MariaDB root
    // password (configured as MARIADB_ROOT_PASSWORD=syspass in docker-compose.yml).
    const dbHost = page.locator('#dbhost');
    await dbHost.fill('db');
    await expect(dbHost).toHaveValue('db');

    // dbpass id is renamed by passwordDetect(); use name= selector.
    await page.locator('[name="dbpass"]').fill('syspass');

    // Other required fields keep their defaults (dbuser=root, dbname=syspass).
    await expect(page.locator('#dbuser')).toHaveValue('root');
    await expect(page.locator('#dbname')).toHaveValue('syspass');

    await btnNext.click();

    // ── Step 4 — sysPass Admin ─────────────────────────────────────────────
    const adminSection = page.locator('#adminaccount');
    await expect(adminSection).toBeVisible();

    // The admin login defaults to "admin".
    await expect(page.locator('#adminlogin')).toHaveValue('admin');

    // Password field IDs are renamed at runtime by passwordDetect(); use name=.
    const adminPass = page.locator('[name="adminpass"]');
    const adminPassR = page.locator('[name="adminpassr"]');
    await adminPass.fill(ADMIN_PASS);
    await adminPassR.fill(ADMIN_PASS);

    // Blur the repeat field before clicking — keeps PKI encryption state
    // consistent when the pointer-based click moves focus to the button.
    await adminPassR.blur();
    await expect(btnNext).toHaveClass(/mdl-button--accent/);

    await btnNext.click();

    // ── Step 5 — Master Password ───────────────────────────────────────────
    const masterSection = page.locator('#masterpwd');
    await expect(masterSection).toBeVisible();

    // On step 5 the Next button is hidden and Install is shown.
    const btnInstall = page.locator('#btnInstall');
    await expect(btnInstall).toBeVisible();
    await expect(btnNext).toBeHidden();

    // Same pattern: name= selector (id is renamed), blur repeat before Install.
    const masterPass = page.locator('[name="masterpassword"]');
    const masterPassR = page.locator('[name="masterpasswordr"]');
    await masterPass.fill(MASTER_PASS);
    await masterPassR.fill(MASTER_PASS);

    await masterPassR.blur();
    await expect(btnInstall).toHaveClass(/mdl-button--accent/);

    // ── Submit — wait for the async install POST to complete ───────────────
    // The install creates the DB schema, admin account, restricted DB user,
    // and config.xml. Allow up to 30 s.
    await Promise.all([
      page.waitForResponse(
        (resp) => resp.url().includes('install/install') && resp.status() === 200,
        { timeout: 30_000 }
      ),
      btnInstall.click(),
    ]);

    // ── Success screen ─────────────────────────────────────────────────────
    const successPanel = page.locator('#installer-success');
    await expect(successPanel).toBeVisible({ timeout: 10_000 });
    await expect(successPanel).toContainText('Installation finished');

    // The LOGIN button points to the login route.
    await expect(successPanel.locator('a[href*="r=login"]')).toBeVisible();
  });
});
