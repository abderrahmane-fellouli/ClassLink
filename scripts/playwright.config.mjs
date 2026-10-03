import { defineConfig } from '@playwright/test'
export default defineConfig({
  testDir: './browser',
  outputDir: '../artifacts/browser',
  reporter: [['list']],
  use: { baseURL: 'http://127.0.0.1:4173', trace: 'retain-on-failure' },
  projects: ['chromium', 'firefox'].map(browserName => ({ name: browserName, use: { browserName } })),
  webServer: {
    command: 'npm --prefix ../frontend run preview -- --host 127.0.0.1 --port 4173',
    url: 'http://127.0.0.1:4173',
    reuseExistingServer: !process.env.CI,
  },
})
