// @ts-check
const { defineConfig, devices } = require('@playwright/test');

module.exports = defineConfig({
  testDir: './tests/e2e',

  // One retry — guards against transient Docker network blips.
  retries: 1,

  // Run specs in sequence so the install-wizard and login specs share
  // a known app state without stepping on each other.
  workers: 1,

  // In CI, also emit an HTML report (self-contained, non-interactive) alongside
  // the console list so a failed run's traces/screenshots are easy to inspect
  // from the uploaded artifact.
  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',

  use: {
    baseURL: 'http://localhost:8090',
    headless: true,
    launchOptions: {
      args: ['--no-sandbox'],
    },
    // Generous action/navigation timeouts for the install POST (DB create + schema).
    actionTimeout: 15_000,
    navigationTimeout: 30_000,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
    video: 'off',
  },

  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],

  // No webServer block — the Docker app is already running on :8090.
});
