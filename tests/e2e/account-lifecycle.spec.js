// @ts-check
const { test, expect } = require('@playwright/test');
const { execSync } = require('child_process');
const { ADMIN_USER, ADMIN_PASS, MASTER_PASS } = require('./credentials.js');

// ---------------------------------------------------------------------------
// Fixture helpers — write directly to MariaDB via docker compose
//
// Same helpers as password-copy.spec.js: a client and a category are needed
// before an account can be created, and the account form only offers a
// dropdown over what already exists — it has no inline "create" flow this
// suite can drive without adding a second popup form to the picture.
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
// Login + password-field helpers
// ---------------------------------------------------------------------------

/**
 * Fill a password field and wait until sysPass has PKI-encrypted it
 * client-side before returning.
 *
 * sysPass encrypts a `:input[type=password]` value on blur (bindPassEncrypt in
 * app-main.min.js), but only once the RSA public key has loaded
 * (sysPassApp.config.PKI.AVAILABLE). Submitting before that races the
 * encryption and the server either rejects the request or falls back to the
 * raw value. We therefore: (a) wait for PKI to be available, (b) fill the
 * field, (c) blur it (by clicking another element on the page) to trigger
 * encryption, and (d) wait for the `data-length` attribute the encrypt
 * handler sets on success. If PKI never becomes available (no key served)
 * the raw value is submitted and the server's decrypt-or-raw fallback
 * handles it — mirrors login.spec.js / password-copy.spec.js.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} selector       The password field to fill.
 * @param {string} value
 * @param {string} blurTarget     Selector of another element to click, to blur `selector`.
 */
async function fillPassword(page, selector, value, blurTarget) {
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
  await page.locator(blurTarget).click();
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

/**
 * Log in to the sysPass app with the admin account, handling the optional
 * master-password prompt that appears on the first login of a new session.
 *
 * Mirrors login.spec.js / password-copy.spec.js.
 */
async function login(page) {
  await page.goto('/index.php?r=login');

  const loginBox = page.locator('#box-login');
  await expect(loginBox).toBeVisible();

  await page.locator('#user').fill(ADMIN_USER);
  await fillPassword(page, '#pass', ADMIN_PASS, '#user');

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
    await fillPassword(page, '#mpass', MASTER_PASS, '#user');

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

/**
 * Pick a value from one of the account form's selectize-enhanced <select>
 * fields (client_id / category_id): the plain PHP-rendered <select> is
 * hidden and replaced with a text input + dropdown, so a real user opens the
 * dropdown and clicks the option rather than using <select> semantics.
 *
 * The dropdown for a given field is the sibling `.selectize-control` that
 * selectize inserts right after the original (hidden) <select id="fieldId">.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} fieldId
 * @param {number} value
 */
async function selectizePick(page, fieldId, value) {
  await page.locator(`#${fieldId}-selectized`).click();
  const option = page.locator(
    `#${fieldId} + .selectize-control .selectize-dropdown-content [data-value="${value}"]`
  );
  await expect(option).toBeVisible({ timeout: 5_000 });
  await option.click();
}

// ---------------------------------------------------------------------------
// Test
// ---------------------------------------------------------------------------

test.describe('Account lifecycle', () => {
  /**
   * Creates an account through the real account form (name, client,
   * category, login, password — the password PKI-encrypted client-side
   * exactly as a real browser session does it), reveals the password
   * through the view-password popup and asserts it matches what was typed,
   * then deletes the account and confirms it is gone from the search
   * results.
   *
   * This is the one place the browser suite exercises the client-side PKI
   * encryption / server-side decryption round trip for an *account*
   * password (as opposed to the login password, which login.spec.js and
   * password-copy.spec.js already cover): the account view-password popup
   * (viewpass.inc) renders the decrypted password as plain text into
   * `.dialog-pass-text`, so comparing that text against the typed value is
   * a direct, in-browser proof the round trip preserved it byte-for-byte —
   * no clipboard permissions required.
   */
  test('creates an account, reveals its password, and deletes it', async ({ page }) => {
    // accountId is set once the real form has created the account (step 5).
    // The `finally` block below always removes it directly from the database
    // — belt-and-braces cleanup independent of whether the *UI* delete flow
    // (step 8) actually works, since that is itself under test here.
    let accountId;

    // ── 1. Ensure a client and a category exist ─────────────────────────────
    const clientId = ensureClient('Playwright Lifecycle Client');
    const categoryId = ensureCategory('Playwright Lifecycle Category');

    const accountName = 'Playwright Lifecycle Account';
    const accountLogin = 'lifecycle-user';
    const accountPassword = 'LifecyclePass1!';

    try {
      // ── 2. Login ────────────────────────────────────────────────────────
      await login(page);

      // Wait for sysPassApp to be fully initialised (CSRF ready) before
      // driving any further UI actions — mirrors password-copy.spec.js.
      await page.waitForFunction(
        () =>
          typeof sysPassApp !== 'undefined' &&
          sysPassApp.config &&
          Boolean(sysPassApp.config.CSRF),
        { timeout: 15_000 }
      );

      // ── 3. Open the "New Account" form from the header nav ──────────────
      const newAccountLink = page.locator(
        'nav.mdl-layout--large-screen-only a.btn-menu[data-view="account"][data-route="account/create"]'
      );
      await expect(newAccountLink).toBeVisible({ timeout: 15_000 });
      await newAccountLink.click();

      const form = page.locator('#frmAccount');
      await expect(form).toBeVisible({ timeout: 15_000 });

      // ── 4. Fill the real form: name, client, category, login, password ──
      await page.locator('#name').fill(accountName);
      await page.locator('#login').fill(accountLogin);

      await selectizePick(page, 'client_id', clientId);
      await selectizePick(page, 'category_id', categoryId);

      // Both password fields are PKI-encrypted independently on blur, and
      // the server compares their decrypted values — fill and blur each in
      // turn.
      //
      // passwordDetect() (app-theme.js) renames these fields' DOM ids at
      // init time (e.g. "password" becomes "password-<uid>") to attach the
      // show/generate-password affordances, exactly like the install
      // wizard's admin/master password fields — so, as
      // install-wizard.spec.js does, use the unchanging name= attribute
      // rather than the id.
      await fillPassword(page, '[name="password"]', accountPassword, '#name');
      await fillPassword(page, '[name="password_repeat"]', accountPassword, '#name');

      // ── 5. Submit via the real Save button ───────────────────────────────
      const saveBtn = page.locator('button[data-onclick="account/save"][type="submit"]');
      await expect(saveBtn).toBeVisible();

      const [saveResp] = await Promise.all([
        page.waitForResponse(
          (r) => r.url().includes('saveCreate') && r.status() === 200,
          { timeout: 15_000 }
        ),
        saveBtn.click(),
      ]);

      const saveBody = await saveResp.json().catch(() => null);
      expect(saveBody && saveBody.data && saveBody.data.itemId).toBeTruthy();
      accountId = Number(saveBody.data.itemId);

      // ── 6. Find the new account in the search results ───────────────────
      await page.evaluate(() => {
        sysPassApp.actions.getContent({ r: 'account/index' }, 'search');
      });

      const viewPassBtn = page.locator(
        `i.btn-action[data-onclick="account/viewPass"][data-item-id="${accountId}"]`
      );
      await expect(viewPassBtn).toBeVisible({ timeout: 15_000 });

      // ── 7. Reveal the password and assert it matches what was typed ─────
      await Promise.all([
        page.waitForResponse(
          (r) => r.url().includes('viewPass') && r.status() === 200,
          { timeout: 10_000 }
        ),
        viewPassBtn.click(),
      ]);

      const popup = page.locator('#box-popup');
      await expect(popup).toBeVisible({ timeout: 10_000 });

      const passText = popup.locator('.dialog-pass-text');
      await expect(passText).toBeVisible();

      // This is the assertion that matters: the password read back from the
      // server — after client-side PKI encryption, the network round trip
      // and server-side decryption — is byte-for-byte the password that was
      // typed into the form.
      await expect(passText).toHaveText(accountPassword);

      // The username row is right there too — cheap extra confirmation that
      // this is really our account.
      await expect(popup.locator('.dialog-user-text')).toHaveText(accountLogin);

      // Close the popup — viewpass.inc has no explicit close button, so
      // drive magnificPopup directly (it auto-closes after 30s otherwise).
      await page.evaluate(() => {
        // eslint-disable-next-line no-undef
        $.magnificPopup.close();
      });
      await expect(popup).toBeHidden({ timeout: 5_000 });

      // ── 8. Delete the account ────────────────────────────────────────────
      await page.evaluate(() => {
        sysPassApp.actions.getContent({ r: 'account/index' }, 'search');
      });

      // With the default (non-"optional actions") user preference, Delete
      // (like Edit and Copy) is not one of the always-visible row icons —
      // it's grouped behind that row's own "more_vert" overflow menu
      // (AccountSearchHelper::getGrid() adds it with the isMenuAction
      // flag). Open that row's menu first, exactly like a real user would.
      const row = page.locator('.account-label', {
        has: page.locator(`i[data-onclick="account/viewPass"][data-item-id="${accountId}"]`),
      });
      await expect(row).toBeVisible({ timeout: 15_000 });
      await row.locator('button.mdl-button--icon').click();

      const deleteBtn = row.locator(
        `li.btn-action[data-onclick="account/delete"][data-item-id="${accountId}"]`
      );
      await expect(deleteBtn).toBeVisible({ timeout: 5_000 });
      await deleteBtn.click();

      // account:delete opens an mdlDialog confirmation; its positive (OK)
      // button keeps the library default id "positive".
      const confirmBtn = page.locator('#positive');
      await expect(confirmBtn).toBeVisible({ timeout: 5_000 });

      // The route has to reach the server in the query string: sysPass resolves
      // the controller from `r` in the query only, so a POST carrying it in the
      // body alone falls through to the default route and answers with the
      // ordinary page — the dialog closes, the list refreshes, and nothing is
      // deleted. That is what this step used to observe; the request is matched
      // on its URL for exactly that reason.
      const [deleteResp] = await Promise.all([
        page.waitForResponse(
          (r) =>
            r.request().method() === 'POST' &&
            r.url().includes('index.php') &&
            r.url().includes('saveDelete'),
          { timeout: 10_000 }
        ),
        confirmBtn.click(),
      ]);
      expect(deleteResp.status(), 'account/saveDelete response status').toBe(200);

      // ── 9. Assert it is gone from the list ───────────────────────────────
      await page.evaluate(() => {
        sysPassApp.actions.getContent({ r: 'account/index' }, 'search');
      });

      await expect(
        page.locator(`i.btn-action[data-onclick="account/viewPass"][data-item-id="${accountId}"]`),
        'the account is gone from the list once the delete has been confirmed'
      ).toHaveCount(0, { timeout: 15_000 });
      await expect(page.locator(`.account-label:has-text("${accountName}")`)).toHaveCount(0);
    } finally {
      // Leave the installation as found regardless of whether the UI delete
      // flow actually removed the account.
      if (accountId) {
        dbExec(`DELETE FROM Account WHERE id = ${accountId}`);
      }
    }
  });
});
