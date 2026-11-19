// @ts-check
const { test, expect } = require('@playwright/test');
const { execSync } = require('child_process');

// ---------------------------------------------------------------------------
// The master-password prompt at sign-in
//
// A user who has never unlocked the master password — every user an
// administrator creates, until their first sign-in — has to supply it with
// their own password. The server answers the first attempt by asking for it,
// and the form shows the field — for the temporary master password an
// administrator issues. It never did: every refusal reached the browser
// as a plain ERROR, the form switches on a status the response did not carry,
// and the user was told the master password was wrong without ever being shown
// where to type it.
//
// Named to sort after install-wizard.spec.js: it needs an installed instance.
// ---------------------------------------------------------------------------

const compose = 'docker compose -f docker-compose.yml';
const cwd = `${__dirname}/../..`;

const USER_LOGIN = 'e2e_mpass_prompt';
const USER_PASS = 'PromptUserPass1!';

function dbExec(sql) {
  execSync(`${compose} exec -T db mariadb -uroot -psyspass syspass -e "${sql}"`, { cwd, stdio: 'pipe' });
}

/**
 * The application's own hash for a login password, as hex: an Argon2id hash
 * carries '$' signs the shell would otherwise expand.
 */
function hashPassword(password) {
  return execSync(
    `${compose} exec -T app php -r 'echo bin2hex(password_hash($argv[1], PASSWORD_ARGON2ID));' -- '${password}'`,
    { cwd, stdio: 'pipe' }
  ).toString().trim();
}

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

test.describe('Master password prompt at sign-in', () => {
  test.beforeAll(() => {
    dbExec(`DELETE FROM User WHERE login = '${USER_LOGIN}'`);
    // No mPass: this user has never unlocked the master password.
    dbExec(
      'INSERT INTO User (name, userGroupId, login, pass, email, userProfileId, hashSalt, isAdminApp) '
      + `VALUES ('E2E prompt user', 1, '${USER_LOGIN}', UNHEX('${hashPassword(USER_PASS)}'), `
      + `'${USER_LOGIN}@example.org', 1, '', 0)`
    );
  });

  test.afterAll(() => {
    dbExec(`DELETE FROM User WHERE login = '${USER_LOGIN}'`);
  });

  test('asks for the master password and shows the field for it', async ({ page }) => {
    await page.goto('/index.php?r=login');
    await expect(page.locator('#box-login')).toBeVisible();
    await expect(page.locator('#smpass')).toBeHidden();

    await page.locator('#user').fill(USER_LOGIN);
    await fillPassword(page, '#pass', USER_PASS, '#user');

    const first = await submit(page);

    // The response says which field the form has to show, and the form shows it. What the
    // field takes is the temporary master password an administrator issues for this user,
    // which completing the sign-in would need; this test is about the prompt appearing.
    expect(first && first.data && first.data.loginStatus, JSON.stringify(first)).toBe(2);
    await expect(page.locator('#smpass')).toBeVisible({ timeout: 5_000 });
    await expect(page.locator('#mpass')).toBeEnabled();
  });
});
