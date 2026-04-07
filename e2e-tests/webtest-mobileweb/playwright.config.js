const { defineConfig } = require("../api-v1/node_modules/@playwright/test");
const { cfg } = require("./src/config");

module.exports = defineConfig({
  testDir: "./tests",
  timeout: cfg.testTimeoutMs,
  expect: {
    timeout: cfg.expectTimeoutMs,
  },
  fullyParallel: true,
  workers: cfg.workers,
  reporter: [["list"]],
  use: {
    baseURL: cfg.baseUrl,
    headless: true,
    trace: "retain-on-failure",
  },
  webServer: cfg.skipWebServer
    ? undefined
    : {
        command: "npm run dev -- --host 127.0.0.1 --port 4174",
        cwd: cfg.mobileRepoPath,
        url: cfg.baseUrl,
        reuseExistingServer: true,
        timeout: cfg.webServerTimeoutMs,
      },
});
