// @ts-check
const { test, expect } = require('@playwright/test');
const { execSync } = require('child_process');
const { ADMIN_USER, ADMIN_PASS, MASTER_PASS } = require('./credentials.js');

// ---------------------------------------------------------------------------
// Fixture helpers — write directly to MariaDB via docker compose
// ---------------------------------------------------------------------------

/** docker compose base command (run from the repo root) */
const compose = 'docker compose -f docker-compose.yml';
// docker-compose.yml lives at the repo root — two levels up from tests/e2e/.
const cwd = `${__dirname}/../..`;

/**
 * Run a MariaDB statement against the syspass database.
 *
 * @param {string} sql
 */
function dbExec(sql) {
  execSync(
    `${compose} exec -T db mariadb -uroot -psyspass syspass -e "${sql}"`,
    { cwd, stdio: 'pipe' }
  );
}

/**
 * Run a MariaDB query and return the first column of the first result row
 * as a trimmed string.
 *
 * @param {string} sql
 * @returns {string}
 */
function dbQueryOne(sql) {
  return execSync(
    `${compose} exec -T db mariadb -uroot -psyspass syspass -Nse "${sql}"`,
    { cwd, stdio: 'pipe' }
  )
    .toString()
    .trim();
}

/**
 * Replicate the PHP makeItemHash() logic so we can compute it in Node.js.
 * Used to supply the `hash` NOT NULL column when inserting via raw SQL.
 *
 *   https://github.com/OpusProjects/sysPass — RepositoryItemTrait::makeItemHash()
 */
function makeItemHash(name) {
  const { createHash } = require('crypto');
  const chars = ['.', ' ', '_', ', ', '-', ';', "'", '"', ':', '(', ')', '|', '/'];
  let s = name;
  for (const c of chars) {
    s = s.split(c).join('');
  }
  return createHash('sha1').update(s.toLowerCase()).digest('hex');
}

/**
 * Look up a row by hash; if absent, insert it and look up again.
 * Returns the row's id as a Number.
 *
 * NOTE: Client.hash and Category.hash have a plain KEY (not UNIQUE KEY), so
 * INSERT IGNORE does NOT prevent duplicate inserts.  We therefore check first.
 *
 * @param {string} table
 * @param {string} hash
 * @param {string} insertSql  Full INSERT … statement (no trailing semicolon)
 * @returns {number}
 */
function ensureRow(table, hash, insertSql) {
  const existing = dbQueryOne(
    `SELECT id FROM ${table} WHERE hash = '${hash}' ORDER BY id LIMIT 1`
  );
  if (!existing) {
    dbExec(insertSql);
  }
  const id = Number(
    dbQueryOne(
      `SELECT id FROM ${table} WHERE hash = '${hash}' ORDER BY id LIMIT 1`
    )
  );
  if (!id) {
    throw new Error(`ensureRow(${table}): could not find/create row with hash ${hash}`);
  }
  return id;
}

/**
 * Ensure a global client (isGlobal=1) exists; return its id.
 *
 * @param {string} name
 * @returns {number}
 */
function ensureClient(name) {
  const hash = makeItemHash(name);
  return ensureRow(
    'Client',
    hash,
    `INSERT INTO Client (name, hash, isGlobal) VALUES ('${name}', '${hash}', 1)`
  );
}

/**
 * Ensure a category exists; return its id.
 *
 * @param {string} name
 * @returns {number}
 */
function ensureCategory(name) {
  const hash = makeItemHash(name);
  return ensureRow(
    'Category',
    hash,
    `INSERT INTO Category (name, hash) VALUES ('${name}', '${hash}')`
  );
}

// ---------------------------------------------------------------------------
// Login helper
// ---------------------------------------------------------------------------

/**
 * Log in to the sysPass app with the admin account, handling the optional
 * master-password prompt that appears on the first login of a new session.
 *
 * Mirrors the pattern used in login.spec.js.
 */
/**
 * Fill the login password field and wait until sysPass has PKI-encrypted it
 * client-side before returning.
 *
 * sysPass encrypts the #pass value on blur (bindPassEncrypt), but only once the
 * RSA public key has loaded (sysPassApp.config.PKI.AVAILABLE). Submitting before
 * that races the encryption and the server rejects the login as "Wrong login".
 * We therefore: (a) wait for PKI to be available, (b) fill the field, (c) blur it
 * to trigger encryption, and (d) wait for the `data-length` attribute the encrypt
 * handler sets on success. If PKI never becomes available (no key served) the
 * raw value is submitted and the server's decrypt-or-raw fallback handles it.
 */
async function fillPassword(page, selector, value) {
  await page.waitForFunction(
    () =>
      typeof sysPassApp !== 'undefined' &&
      sysPassApp.config &&
      sysPassApp.config.PKI &&
      sysPassApp.config.PKI.AVAILABLE === true,
    { timeout: 15_000 }
  ).catch(() => {}); // tolerate installs that serve no PKI key

  const field = page.locator(selector);
  await field.fill(value);
  // Blur to fire the encrypt handler, then confirm it ran.
  await page.locator('#user').click();
  await page.waitForFunction(
    (sel) => {
      const el = document.querySelector(sel);
      const pkiOn =
        typeof sysPassApp !== 'undefined' &&
        sysPassApp.config &&
        sysPassApp.config.PKI &&
        sysPassApp.config.PKI.AVAILABLE === true;
      // Encrypted when data-length is set; if PKI is off the raw value stands.
      return !pkiOn || (el && el.getAttribute('data-length'));
    },
    selector,
    { timeout: 10_000 }
  );
}

async function login(page) {
  await page.goto('/index.php?r=login');

  const loginBox = page.locator('#box-login');
  await expect(loginBox).toBeVisible();

  await page.locator('#user').fill(ADMIN_USER);
  await fillPassword(page, '#pass', ADMIN_PASS);

  const [resp] = await Promise.all([
    page.waitForResponse(
      (r) => r.url().includes('login/login') && r.status() === 200,
      { timeout: 15_000 }
    ),
    page.locator('#btnLogin').click(),
  ]);

  const body = await resp.json().catch(() => null);
  const masterPassDiv = page.locator('#smpass');
  const masterPassVisible = await masterPassDiv.isVisible();

  if (masterPassVisible || (body && body.status !== 'OK' && body.status !== 0)) {
    await expect(masterPassDiv).toBeVisible({ timeout: 5_000 });
    await fillPassword(page, '#mpass', MASTER_PASS);

    await Promise.all([
      page.waitForResponse(
        (r) => r.url().includes('login/login') && r.status() === 200,
        { timeout: 15_000 }
      ),
      page.locator('#btnLogin').click(),
    ]);
  }

  await expect(loginBox).toBeHidden({ timeout: 15_000 });
  await expect(page.locator('#user-name')).toBeVisible({ timeout: 15_000 });
}

// ---------------------------------------------------------------------------
// Test
// ---------------------------------------------------------------------------

test.describe('Account copy-password', () => {
  /**
   * Full E2E path through the view-password dialog copy button.
   *
   * Design:
   *  1. Create a (global) client and a category directly in MariaDB via Docker.
   *     This bypasses the items/clients filter (which only returns clients that
   *     have at least one account or isGlobal=1) and the need to look up IDs
   *     through the PHP API.
   *  2. Log in as admin; wait for sysPassApp initialisation (CSRF ready).
   *  3. Create an account for the new client/category via a direct POST inside
   *     page.evaluate.  The account/saveCreate response returns itemId.
   *  4. Reload the account search; click the "View password" icon for that
   *     account.  magnificPopup renders the viewPass dialog.
   *  5. Click .dialog-clip-button for the password row.
   *     The clipboard.copy(...).then(success) handler adds the CSS class
   *     `dialog-clip-copy` to .dialog-pass-text.  Assert that class.
   *     This is the signal that breaks under clipboard.js 2.x without the
   *     Promise-shim adaptation — that's exactly what Part 2 of the PR guards.
   */
  test('clicking the copy-password button shows the success indicator', async ({ page }) => {
    // ── 1. Create test fixtures in the DB ───────────────────────────────────
    const clientId = ensureClient('Playwright Test Client');
    const categoryId = ensureCategory('Playwright Test Category');
    // account/saveCreate requires main_usergroup_id (NOT NULL column with no
    // server-side default); read it from the admin user row.
    const adminGroupId = Number(
      dbQueryOne(`SELECT userGroupId FROM User WHERE login = 'admin'`)
    );

    // ── 2. Login ────────────────────────────────────────────────────────────
    await login(page);

    // ── 3. Wait for sysPassApp to be fully initialised ─────────────────────
    // getEnvironment() must have completed (sets CSRF on front-end + session).
    await page.waitForFunction(
      () =>
        typeof sysPassApp !== 'undefined' &&
        sysPassApp.config &&
        Boolean(sysPassApp.config.CSRF),
      { timeout: 15_000 }
    );

    // ── 4. Create account via AJAX POST ────────────────────────────────────
    // The session cookie + X-CSRF header satisfy both auth checks.
    // Raw password is accepted — analyzeEncrypted() falls back to the raw
    // value when RSA decryption fails (scripted-install path).
    const accountId = await page.evaluate(
      async ({ clientId, categoryId, adminGroupId }) => {
        const csrf = sysPassApp.config.CSRF;
        const sk = sysPassApp.sk.get();
        const root = sysPassApp.config.APP_ROOT;

        const body = new URLSearchParams({
          name: 'Playwright Copy Test',
          login: 'pwtest',
          client_id: String(clientId),
          category_id: String(categoryId),
          main_usergroup_id: String(adminGroupId),
          password: 'CopyTestPass1!',
          password_repeat: 'CopyTestPass1!',
          sk,
          isAjax: '1',
        });

        const r = await fetch(`${root}/index.php?r=account/saveCreate`, {
          method: 'POST',
          headers: {
            'X-CSRF': csrf,
            'X-Requested-With': 'XMLHttpRequest',
            'Content-Type': 'application/x-www-form-urlencoded',
          },
          body: body.toString(),
        });

        const json = await r.json();

        if (!json.data || !json.data.itemId) {
          throw new Error(`account/saveCreate failed: ${JSON.stringify(json)}`);
        }

        return Number(json.data.itemId);
      },
      { clientId, categoryId, adminGroupId }
    );

    // ── 5. Reload the account search ────────────────────────────────────────
    await page.evaluate(() => {
      sysPassApp.actions.getContent({ r: 'account/index' }, 'search');
    });

    // Wait for the new account's viewPass button to appear in the search list.
    const viewPassBtn = page.locator(
      `i.btn-action[data-onclick="account/viewPass"][data-item-id="${accountId}"]`
    );
    await expect(viewPassBtn).toBeVisible({ timeout: 15_000 });

    // ── 6. Open the view-password popup ─────────────────────────────────────
    await Promise.all([
      page.waitForResponse(
        (r) => r.url().includes('viewPass') && r.status() === 200,
        { timeout: 10_000 }
      ),
      viewPassBtn.click(),
    ]);

    const popup = page.locator('#box-popup');
    await expect(popup).toBeVisible({ timeout: 10_000 });

    // The password row must be present (non-image mode).
    const passText = popup.locator('.dialog-pass-text');
    await expect(passText).toBeVisible();

    // ── 7. Click the copy-password button ───────────────────────────────────
    const copyPassBtn = popup.locator(
      'button.dialog-clip-button[data-clipboard-target=".dialog-pass-text"]'
    );
    await expect(copyPassBtn).toBeVisible();
    await copyPassBtn.click();

    // ── 8. Assert the .then(success) callback fired ─────────────────────────
    // clipboard.copy(...).then(success) adds dialog-clip-copy to .dialog-pass-text.
    // The error handler never adds this class — only the success path does.
    await expect(passText).toHaveClass(/dialog-clip-copy/, { timeout: 5_000 });
  });
});
