// @ts-check
const { test, expect } = require('@playwright/test');
const { execSync } = require('child_process');
const { createHmac } = require('crypto');
const { ADMIN_USER, ADMIN_PASS, MASTER_PASS } = require('./credentials.js');

// ---------------------------------------------------------------------------
// Two-factor sign-in, end to end in the browser
//
// Turns the second factor on from the user's Security tab — the QR code is
// drawn, the secret shown as text, a code from it confirms — checks the
// recovery codes are handed over, then signs in again and is asked for a code.
// The code is computed here from the secret the page showed, as an
// authenticator app would.
//
// The administrator is the user, so afterAll removes the secret: every spec
// after this one signs in with the password alone. Named to sort after
// install-wizard.spec.js, since it needs an installed instance.
// ---------------------------------------------------------------------------

const compose = 'docker compose -f docker-compose.yml';
const cwd = `${__dirname}/../..`;

function dbExec(sql) {
  execSync(`${compose} exec -T db mariadb -uroot -psyspass syspass -e "${sql}"`, { cwd, stdio: 'pipe' });
}

/**
 * RFC 4648 base32, as the secret is shown.
 */
function base32Decode(text) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  const out = [];
  let bits = 0;
  let value = 0;

  for (const ch of text.replace(/[\s=]/g, '').toUpperCase()) {
    value = ((value << 5) | alphabet.indexOf(ch)) & 0xffffff;
    bits += 5;

    if (bits >= 8) {
      out.push((value >>> (bits - 8)) & 0xff);
      bits -= 8;
    }
  }

  return Buffer.from(out);
}

/**
 * RFC 6238 with the defaults every authenticator app uses: SHA-1, six digits.
 */
function totpCode(secret, step) {
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(step));

  const mac = createHmac('sha1', base32Decode(secret)).update(counter).digest();
  const offset = mac[19] & 0x0f;
  const value = ((mac[offset] & 0x7f) << 24) | (mac[offset + 1] << 16) | (mac[offset + 2] << 8) | mac[offset + 3];

  return String(value % 1_000_000).padStart(6, '0');
}

const currentStep = () => Math.floor(Date.now() / 1000 / 30);

async function fillPassword(page, selector, value, blurTarget) {
  await page.waitForFunction(
    () => typeof sysPassApp !== 'undefined' && sysPassApp.config && sysPassApp.config.PKI
      && sysPassApp.config.PKI.AVAILABLE === true,
    { timeout: 15_000 }
  ).catch(() => {});

  await page.locator(selector).fill(value);
  await page.locator(blurTarget).click();
  await page.waitForFunction(
    (sel) => {
      const el = document.querySelector(sel);
      const pkiOn = typeof sysPassApp !== 'undefined' && sysPassApp.config && sysPassApp.config.PKI
        && sysPassApp.config.PKI.AVAILABLE === true;
      return !pkiOn || (el && el.getAttribute('data-length'));
    },
    selector,
    { timeout: 10_000 }
  );
}

async function submit(page) {
  const [resp] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('login/login') && r.status() === 200, { timeout: 15_000 }),
    page.locator('#btnLogin').click(),
  ]);

  return resp.json().catch(() => null);
}

/**
 * Sign in as the administrator, answering the master password prompt if the
 * session asks for it, and the code prompt with `codeFor()` if it asks for one.
 */
async function login(page, codeFor) {
  await page.goto('/index.php?r=login');
  await expect(page.locator('#box-login')).toBeVisible();

  let fields = {};

  for (let attempt = 0; attempt < 3; attempt++) {
    await page.locator('#user').fill(ADMIN_USER);
    await fillPassword(page, '#pass', ADMIN_PASS, '#user');

    if (fields.mpass) {
      await fillPassword(page, '#mpass', MASTER_PASS, '#user');
    }

    if (fields.totp) {
      await page.locator('#totp').fill(codeFor());
    }

    const body = await submit(page);
    const status = body && body.data && body.data.loginStatus;

    if (status === 2) {
      fields.mpass = true;
    } else if (status === 8) {
      expect(codeFor, 'asked for a code nobody can give').toBeTruthy();
      await expect(page.locator('#stotp')).toBeVisible({ timeout: 5_000 });
      fields.totp = true;
    } else {
      break;
    }
  }

  await expect(page.locator('#box-login')).toBeHidden({ timeout: 15_000 });
  await expect(page.locator('#user-name')).toBeVisible({ timeout: 15_000 });

  return fields;
}

test.describe('Two-factor sign-in', () => {
  const reset = () => dbExec(
    `DELETE FROM UserTotpRecovery; DELETE FROM UserTotp; UPDATE User SET isTotpRequired = 0 WHERE login = '${ADMIN_USER}'`
  );

  test.beforeAll(reset);
  test.afterAll(reset);

  test('turns it on from the Security tab and asks for a code at the next sign-in', async ({ page }) => {
    await login(page, null);

    await page.waitForFunction(
      () => typeof sysPassApp !== 'undefined' && sysPassApp.config && Boolean(sysPassApp.config.CSRF),
      { timeout: 15_000 }
    );

    // ── Open the user's settings, Security tab ────────────────────────────
    // Through the user menu in the header: the drawer's link to the same page
    // reads as visible but sits under the closed drawer and never takes a click.
    await page.locator('#users-menu-lower-right').click();
    await expect(page.locator('#btnPrefs')).toBeVisible({ timeout: 5_000 });
    await page.locator('#btnPrefs').click();
    await page.locator('#tabsHeader a', { hasText: 'Security' }).click();
    await expect(page.locator('#totp-off')).toBeVisible({ timeout: 15_000 });

    // ── Turn on: the QR code is drawn and the secret shown ────────────────
    await page.locator('#totp-off [data-onclick="user/totp"]').click();
    await expect(page.locator('#totp-enrol')).toBeVisible({ timeout: 15_000 });
    await expect(page.locator('#totp-qr svg')).toBeVisible();

    const secret = (await page.locator('#totp-secret').textContent() || '').trim();
    expect(secret).toMatch(/^[A-Z2-7]{32}$/);

    // ── Confirm with a code from it: recovery codes are handed over ───────
    const confirmedAt = currentStep();
    await page.locator('#totp-code').fill(totpCode(secret, confirmedAt));
    await page.locator('#totp-enrol [data-onclick="user/totp"]').click();

    await expect(page.locator('#totp-codes')).toBeVisible({ timeout: 15_000 });
    await expect(page.locator('#totp-codes-list li')).toHaveCount(10);
    await expect(page.locator('#totp-codes-list li').first()).toHaveText(/^[a-z2-7]{6}-[a-z2-7]{6}$/);

    await page.locator('#totp-codes [data-onclick="user/totpDone"]').click();
    await expect(page.locator('#totp-on')).toBeVisible({ timeout: 15_000 });

    // ── Sign in again: the code is asked for, and the next one accepted ───
    // Confirming spent the step it was made in, so the sign-in presents the
    // next one, which the drift window accepts.
    await page.context().clearCookies();

    const fields = await login(page, () => totpCode(secret, Math.max(confirmedAt, currentStep()) + 1));

    expect(fields.totp, 'sign-in did not ask for the code').toBe(true);
  });

  /**
   * Required by an administrator and not enrolled: the sign-in itself draws the
   * QR code, takes a code from it, and shows the recovery codes before going on.
   */
  test('a required user enrols at sign-in and is shown the recovery codes', async ({ page }) => {
    reset();
    dbExec(`UPDATE User SET isTotpRequired = 1 WHERE login = '${ADMIN_USER}'`);

    await page.goto('/index.php?r=login');
    await expect(page.locator('#box-login')).toBeVisible();

    let body = null;
    let mpass = false;

    // The master password may be asked for first on a fresh session; the
    // enrolment prompt comes after the password in either case.
    for (let attempt = 0; attempt < 3; attempt++) {
      await page.locator('#user').fill(ADMIN_USER);
      await fillPassword(page, '#pass', ADMIN_PASS, '#user');

      if (mpass) {
        await fillPassword(page, '#mpass', MASTER_PASS, '#user');
      }

      body = await submit(page);

      if (body && body.data && body.data.loginStatus === 2) {
        mpass = true;
        continue;
      }

      break;
    }

    expect(body && body.data && body.data.loginStatus, JSON.stringify(body)).toBe(9);
    await expect(page.locator('#stotpenrol')).toBeVisible({ timeout: 5_000 });
    await expect(page.locator('#totp-enrol-qr svg')).toBeVisible();
    await expect(page.locator('#stotp')).toBeVisible();

    const secret = (await page.locator('#totp-enrol-secret').textContent() || '').trim();
    expect(secret).toBe(body.data.secret);

    await page.locator('#user').fill(ADMIN_USER);
    await fillPassword(page, '#pass', ADMIN_PASS, '#user');

    if (mpass) {
      await fillPassword(page, '#mpass', MASTER_PASS, '#user');
    }

    await page.locator('#totp').fill(totpCode(secret, currentStep()));

    const done = await submit(page);
    expect(done && done.status, JSON.stringify(done)).toBe('OK');

    // The recovery codes are shown once, before the redirect.
    await expect(page.locator('#box-recovery')).toBeVisible({ timeout: 5_000 });
    await expect(page.locator('#login-recovery-codes li')).toHaveCount(10);

    await page.locator('#btnRecoveryContinue').click();
    await expect(page.locator('#user-name')).toBeVisible({ timeout: 15_000 });
  });
});
