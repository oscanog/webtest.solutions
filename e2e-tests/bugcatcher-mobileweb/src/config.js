const fs = require("node:fs");
const path = require("node:path");
const dotenv = require("../../api-v1/node_modules/dotenv");

const resolvedEnv = (process.env.E2E_ENV ?? "local").trim().toLowerCase();
const envName = resolvedEnv === "production" ? "production" : "local";
const profilePath = path.resolve(__dirname, "..", ".env.local");
if (fs.existsSync(profilePath)) {
  dotenv.config({ path: profilePath, override: false });
}
const sharedProfilePath = path.resolve(__dirname, "..", "..", "api-v1", `.env.${envName}`);
if (fs.existsSync(sharedProfilePath)) {
  dotenv.config({ path: sharedProfilePath, override: false });
}

function readEnv(key, fallback) {
  const value = process.env[key];
  if (typeof value === "string" && value.trim() !== "") {
    return value.trim();
  }
  return fallback;
}

function readBool(key, fallback) {
  const value = process.env[key];
  if (typeof value !== "string" || value.trim() === "") {
    return fallback;
  }
  return ["1", "true", "yes"].includes(value.trim().toLowerCase());
}

function readPositiveInt(key, fallback) {
  const value = process.env[key];
  if (typeof value !== "string" || value.trim() === "") {
    return fallback;
  }
  const parsed = Number.parseInt(value.trim(), 10);
  if (!Number.isFinite(parsed) || parsed <= 0) {
    return fallback;
  }
  return parsed;
}

function account(email, password, systemRole, orgRole, userId) {
  return Object.freeze({ email, password, systemRole, orgRole, userId });
}

const cfg = Object.freeze({
  envName,
  baseUrl: readEnv("E2E_MOBILE_BASE_URL", "http://127.0.0.1:4174").replace(/\/+$/, ""),
  apiBaseUrl: readEnv("E2E_MOBILE_API_BASE_URL", "http://localhost").replace(/\/+$/, ""),
  apiBasePath: `/${readEnv("E2E_MOBILE_API_BASE_PATH", "/bugcatcher/api/v1").replace(/^\/+/, "").replace(/\/+$/, "")}`,
  mobileRepoPath: readEnv("E2E_MOBILE_REPO_PATH", "C:\\projects\\school\\gendejesus\\bugcatcher-mobileweb"),
  skipWebServer: readBool("E2E_SKIP_WEB_SERVER", false),
  orgId: readPositiveInt("E2E_MOBILE_ORG_ID", readPositiveInt("E2E_ORG_ID", 1)),
  projectId: readPositiveInt("E2E_MOBILE_PROJECT_ID", readPositiveInt("E2E_PROJECT_ID", 1)),
  labelId: readPositiveInt("E2E_MOBILE_LABEL_ID", readPositiveInt("E2E_LABEL_ID", 1)),
  workers: readPositiveInt("E2E_MOBILE_WORKERS", 6),
  testTimeoutMs: readPositiveInt("E2E_MOBILE_TEST_TIMEOUT_MS", 20_000),
  expectTimeoutMs: readPositiveInt("E2E_MOBILE_EXPECT_TIMEOUT_MS", 8_000),
  webServerTimeoutMs: readPositiveInt("E2E_MOBILE_WEB_SERVER_TIMEOUT_MS", 120_000),
  accounts: Object.freeze({
    superAdmin: account(readEnv("E2E_SUPER_ADMIN_EMAIL", "superadmin@local.dev"), readEnv("E2E_SUPER_ADMIN_PASSWORD", "DevPass123!"), "super_admin", "owner", readPositiveInt("E2E_SUPER_ADMIN_ID", 1)),
    admin: account(readEnv("E2E_ADMIN_EMAIL", "admin@local.dev"), readEnv("E2E_ADMIN_PASSWORD", "DevPass123!"), "admin", "member", readPositiveInt("E2E_ADMIN_ID", 2)),
    pm: account(readEnv("E2E_PM_EMAIL", "pm@local.dev"), readEnv("E2E_PM_PASSWORD", "DevPass123!"), "user", "Project Manager", readPositiveInt("E2E_PM_ID", 3)),
    seniorDev: account(readEnv("E2E_SENIOR_DEV_EMAIL", "senior@local.dev"), readEnv("E2E_SENIOR_DEV_PASSWORD", "DevPass123!"), "user", "Senior Developer", readPositiveInt("E2E_SENIOR_DEV_ID", 4)),
    juniorDev: account(readEnv("E2E_JUNIOR_DEV_EMAIL", "junior@local.dev"), readEnv("E2E_JUNIOR_DEV_PASSWORD", "DevPass123!"), "user", "Junior Developer", readPositiveInt("E2E_JUNIOR_DEV_ID", 5)),
    qaTester: account(readEnv("E2E_QA_TESTER_EMAIL", "qa@local.dev"), readEnv("E2E_QA_TESTER_PASSWORD", "DevPass123!"), "user", "QA Tester", readPositiveInt("E2E_QA_TESTER_ID", 6)),
    seniorQa: account(readEnv("E2E_SENIOR_QA_EMAIL", "seniorqa@local.dev"), readEnv("E2E_SENIOR_QA_PASSWORD", "DevPass123!"), "user", "Senior QA", readPositiveInt("E2E_SENIOR_QA_ID", 7)),
    qaLead: account(readEnv("E2E_QA_LEAD_EMAIL", "qalead@local.dev"), readEnv("E2E_QA_LEAD_PASSWORD", "DevPass123!"), "user", "QA Lead", readPositiveInt("E2E_QA_LEAD_ID", 8)),
  }),
});

module.exports = { cfg };
