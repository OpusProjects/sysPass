// @ts-check
const { test, expect } = require('@playwright/test');

/**
 * REST API entry-point smoke test.
 *
 * Guards the shared-compiled-container regression: php-di reuses an existing
 * compiled container file as-is without revalidating its definitions, so when
 * every module compiled to the same class name, whichever entry point ran first
 * won and the others silently received its bindings. In a normal deployment the
 * web entry warms the cache first, which left api.php resolving Web\Bootstrap
 * and dying on its protected $module property — every endpoint answered HTTP 200
 * with an empty body.
 *
 * The web request below is therefore load-bearing: it reproduces that ordering.
 * These assertions need no auth token, because "responds at all, as JSON" is
 * exactly what used to break.
 *
 * Runs last (filename sorts after the install-wizard/login specs, workers: 1) so
 * the app is installed by the time it hits the API.
 */
test.describe('REST API entry point', () => {
  test('answers JSON after the web entry point has been used', async ({ request }) => {
    // 1. Exercise the web entry first — this is what warms the compiled container.
    const web = await request.get('/index.php?r=login');
    expect(web.status()).toBe(200);
    expect((await web.text()).length).toBeGreaterThan(0);

    // 2. The API must still answer, with JSON — not an empty 200.
    const api = await request.get('/api.php/api/v1/categories');

    expect(api.status()).toBe(401);
    expect(api.headers()['content-type']).toContain('application/json');

    const body = await api.json();
    expect(body).toHaveProperty('error.message');

    // 3. An unmatched path must reach the API's own catch-all, proving the API
    //    router (not the web one) is what handled the request.
    const missing = await request.get('/api.php/api/v1/no-such-endpoint');
    const missingBody = await missing.json();

    expect(missingBody.error.message).toContain('Not found');
  });
});
