import { defineConfig } from "@playwright/test";
import { cfg } from "./src/config";

export default defineConfig({
  testDir: "./tests",
  timeout: 120_000,
  expect: {
    timeout: 15_000,
  },
  fullyParallel: false,
  workers: 1,
  reporter: [["list"]],
  use: {
    baseURL: cfg.baseUrl,
    extraHTTPHeaders: {
      "X-E2E-Env": cfg.envName,
    },
    ignoreHTTPSErrors: cfg.baseUrl.startsWith("https://"),
  },
});
