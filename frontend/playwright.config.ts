import { defineConfig, devices } from '@playwright/test'
import { loadEnv } from 'vite'
import type { VisualOptions } from './tests/visual/fixtures'
import { STORAGE_STATE } from './tests/visual/paths'

/**
 * Browser-based visual regression for the staff UI (see tests/visual/README.md).
 *
 * - Reuses a running Vite dev server and Laravel API when they are up; otherwise starts them.
 * - The API URL comes from VITE_API_PROXY_TARGET (frontend/.env), the same target Vite proxies to.
 * - Two projects, `ar` (RTL) and `en` (LTR), share one authenticated storageState from `setup`.
 */
const env = { ...loadEnv('development', process.cwd(), ''), ...process.env }
const baseURL = env.VISUAL_BASE_URL || 'http://localhost:5173'
const apiTarget = env.VITE_API_PROXY_TARGET || 'http://127.0.0.1:8000'
const apiPort = new URL(apiTarget).port || '8000'
const vitePort = new URL(baseURL).port || '5173'

export default defineConfig<VisualOptions>({
  testDir: 'tests/visual',
  outputDir: 'test-results/visual',
  snapshotPathTemplate: '{testDir}/__screenshots__/{projectName}/{arg}{ext}',
  fullyParallel: true,
  // `php artisan serve` handles one request at a time, so more workers only queue on the API.
  workers: Number(env.VISUAL_WORKERS || 2),
  retries: 0,
  timeout: 180_000,
  forbidOnly: !!env.CI,
  reporter: [['list'], ['html', { outputFolder: 'playwright-report', open: 'never' }]],
  globalSetup: './tests/visual/global-setup.ts',
  globalTeardown: './tests/visual/global-teardown.ts',
  expect: {
    timeout: 15_000,
    toHaveScreenshot: {
      animations: 'disabled',
      caret: 'hide',
      scale: 'css',
      // Anti-aliasing noise only; real layout changes move far more than 1% of pixels.
      maxDiffPixelRatio: 0.01,
    },
  },
  use: {
    baseURL,
    ...devices['Desktop Chrome'],
    reducedMotion: 'reduce',
    timezoneId: 'Asia/Bahrain',
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
  projects: [
    { name: 'setup', testMatch: /auth\.setup\.ts/ },
    { name: 'ar', testMatch: /\.visual\.ts/, dependencies: ['setup'], use: { storageState: STORAGE_STATE, locale: 'ar-BH', appLocale: 'ar' } },
    { name: 'en', testMatch: /\.visual\.ts/, dependencies: ['setup'], use: { storageState: STORAGE_STATE, locale: 'en-GB', appLocale: 'en' } },
  ],
  webServer: env.VISUAL_BASE_URL
    ? undefined
    : [
        { command: `php artisan serve --port=${apiPort}`, cwd: '../backend', url: `${apiTarget}/up`, reuseExistingServer: true, timeout: 60_000 },
        { command: `npm run dev -- --port ${vitePort} --strictPort`, url: baseURL, reuseExistingServer: true, timeout: 60_000 },
      ],
})
