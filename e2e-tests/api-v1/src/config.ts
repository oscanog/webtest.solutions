import fs from "node:fs";
import path from "node:path";
import dotenv from "dotenv";
import { z } from "zod";

const resolvedEnv = (process.env.E2E_ENV ?? "local").trim().toLowerCase();
const envName = resolvedEnv === "production" ? "production" : "local";
const profilePath = path.resolve(__dirname, "..", `.env.${envName}`);
if (fs.existsSync(profilePath)) {
  dotenv.config({ path: profilePath, override: false });
}

function isBlank(value: unknown): boolean {
  return value === undefined || value === null || String(value).trim() === "";
}

function normalizeApiBasePath(value: string): string {
  const cleaned = `/${value.trim().replace(/^\/+/, "").replace(/\/+$/, "")}`;
  return cleaned === "/" ? "/api/v1" : cleaned;
}

const localFallbacks = {
  E2E_BASE_URL: "http://localhost",
  E2E_API_BASE_PATH: "/bugcatcher/api/v1",
  E2E_ORG_ID: "1",
  E2E_PROJECT_ID: "1",
  E2E_LABEL_ID: "1",

  E2E_SUPER_ADMIN_EMAIL: "superadmin@local.dev",
  E2E_SUPER_ADMIN_PASSWORD: "DevPass123!",
  E2E_SUPER_ADMIN_ID: "1",

  E2E_ADMIN_EMAIL: "admin@local.dev",
  E2E_ADMIN_PASSWORD: "DevPass123!",
  E2E_ADMIN_ID: "2",

  E2E_PM_EMAIL: "pm@local.dev",
  E2E_PM_PASSWORD: "DevPass123!",
  E2E_PM_ID: "3",

  E2E_SENIOR_DEV_EMAIL: "senior@local.dev",
  E2E_SENIOR_DEV_PASSWORD: "DevPass123!",
  E2E_SENIOR_DEV_ID: "4",

  E2E_JUNIOR_DEV_EMAIL: "junior@local.dev",
  E2E_JUNIOR_DEV_PASSWORD: "DevPass123!",
  E2E_JUNIOR_DEV_ID: "5",

  E2E_QA_TESTER_EMAIL: "qa@local.dev",
  E2E_QA_TESTER_PASSWORD: "DevPass123!",
  E2E_QA_TESTER_ID: "6",

  E2E_SENIOR_QA_EMAIL: "seniorqa@local.dev",
  E2E_SENIOR_QA_PASSWORD: "DevPass123!",
  E2E_SENIOR_QA_ID: "7",

  E2E_QA_LEAD_EMAIL: "qalead@local.dev",
  E2E_QA_LEAD_PASSWORD: "DevPass123!",
  E2E_QA_LEAD_ID: "8",

  E2E_OPENCLAW_INTERNAL_TOKEN: "replace-me-too",
  E2E_CHECKLIST_BOT_TOKEN: "replace-me",
} as const;

type FallbackKey = keyof typeof localFallbacks;

const requiredKeys: FallbackKey[] = [
  "E2E_BASE_URL",
  "E2E_API_BASE_PATH",
  "E2E_ORG_ID",
  "E2E_PROJECT_ID",
  "E2E_LABEL_ID",
  "E2E_SUPER_ADMIN_EMAIL",
  "E2E_SUPER_ADMIN_PASSWORD",
  "E2E_SUPER_ADMIN_ID",
  "E2E_ADMIN_EMAIL",
  "E2E_ADMIN_PASSWORD",
  "E2E_ADMIN_ID",
  "E2E_PM_EMAIL",
  "E2E_PM_PASSWORD",
  "E2E_PM_ID",
  "E2E_SENIOR_DEV_EMAIL",
  "E2E_SENIOR_DEV_PASSWORD",
  "E2E_SENIOR_DEV_ID",
  "E2E_JUNIOR_DEV_EMAIL",
  "E2E_JUNIOR_DEV_PASSWORD",
  "E2E_JUNIOR_DEV_ID",
  "E2E_QA_TESTER_EMAIL",
  "E2E_QA_TESTER_PASSWORD",
  "E2E_QA_TESTER_ID",
  "E2E_SENIOR_QA_EMAIL",
  "E2E_SENIOR_QA_PASSWORD",
  "E2E_SENIOR_QA_ID",
  "E2E_QA_LEAD_EMAIL",
  "E2E_QA_LEAD_PASSWORD",
  "E2E_QA_LEAD_ID",
];

const optionalKeys: FallbackKey[] = [
  "E2E_OPENCLAW_INTERNAL_TOKEN",
  "E2E_CHECKLIST_BOT_TOKEN",
];

function resolveEnvValue(key: FallbackKey): string | undefined {
  const current = process.env[key];
  if (!isBlank(current)) {
    return String(current).trim();
  }
  if (envName === "local") {
    return localFallbacks[key];
  }
  return optionalKeys.includes(key) ? undefined : "";
}

const requiredTrimmed = z.preprocess((value) => String(value ?? "").trim(), z.string().min(1));
const requiredPositiveInt = z.preprocess((value) => {
  if (isBlank(value)) {
    return NaN;
  }
  return Number(String(value).trim());
}, z.number().int().positive());
const optionalTrimmed = z.preprocess((value) => {
  if (isBlank(value)) {
    return undefined;
  }
  return String(value).trim();
}, z.string().optional());

const schema = z.object({
  E2E_BASE_URL: z.preprocess((value) => String(value ?? "").trim(), z.string().url()),
  E2E_API_BASE_PATH: requiredTrimmed,
  E2E_ORG_ID: requiredPositiveInt,
  E2E_PROJECT_ID: requiredPositiveInt,
  E2E_LABEL_ID: requiredPositiveInt,

  E2E_SUPER_ADMIN_EMAIL: requiredTrimmed,
  E2E_SUPER_ADMIN_PASSWORD: requiredTrimmed,
  E2E_SUPER_ADMIN_ID: requiredPositiveInt,

  E2E_ADMIN_EMAIL: requiredTrimmed,
  E2E_ADMIN_PASSWORD: requiredTrimmed,
  E2E_ADMIN_ID: requiredPositiveInt,

  E2E_PM_EMAIL: requiredTrimmed,
  E2E_PM_PASSWORD: requiredTrimmed,
  E2E_PM_ID: requiredPositiveInt,

  E2E_SENIOR_DEV_EMAIL: requiredTrimmed,
  E2E_SENIOR_DEV_PASSWORD: requiredTrimmed,
  E2E_SENIOR_DEV_ID: requiredPositiveInt,

  E2E_JUNIOR_DEV_EMAIL: requiredTrimmed,
  E2E_JUNIOR_DEV_PASSWORD: requiredTrimmed,
  E2E_JUNIOR_DEV_ID: requiredPositiveInt,

  E2E_QA_TESTER_EMAIL: requiredTrimmed,
  E2E_QA_TESTER_PASSWORD: requiredTrimmed,
  E2E_QA_TESTER_ID: requiredPositiveInt,

  E2E_SENIOR_QA_EMAIL: requiredTrimmed,
  E2E_SENIOR_QA_PASSWORD: requiredTrimmed,
  E2E_SENIOR_QA_ID: requiredPositiveInt,

  E2E_QA_LEAD_EMAIL: requiredTrimmed,
  E2E_QA_LEAD_PASSWORD: requiredTrimmed,
  E2E_QA_LEAD_ID: requiredPositiveInt,

  E2E_OPENCLAW_INTERNAL_TOKEN: optionalTrimmed,
  E2E_CHECKLIST_BOT_TOKEN: optionalTrimmed,
});

const source: Record<string, string | undefined> = {};
for (const key of requiredKeys) {
  source[key] = resolveEnvValue(key);
}
for (const key of optionalKeys) {
  source[key] = resolveEnvValue(key);
}

const parsed = schema.safeParse(source);
if (!parsed.success) {
  const reasons = parsed.error.issues.map((issue) => {
    const field = issue.path.join(".") || "env";
    return `${field}: ${issue.message}`;
  });
  throw new Error(
    `Invalid API v1 E2E configuration for E2E_ENV=${envName} (${profilePath})\n${reasons.join("\n")}`
  );
}

const env = parsed.data;

function account(email: string, password: string, userId: number) {
  return Object.freeze({ email, password, userId });
}

export const cfg = Object.freeze({
  envName,
  isProduction: envName === "production",
  baseUrl: env.E2E_BASE_URL.replace(/\/+$/, ""),
  apiBasePath: normalizeApiBasePath(env.E2E_API_BASE_PATH),
  orgId: env.E2E_ORG_ID,
  projectId: env.E2E_PROJECT_ID,
  labelId: env.E2E_LABEL_ID,
  openclawInternalToken: env.E2E_OPENCLAW_INTERNAL_TOKEN ?? "",
  checklistBotToken: env.E2E_CHECKLIST_BOT_TOKEN ?? "",
  accounts: Object.freeze({
    superAdmin: account(env.E2E_SUPER_ADMIN_EMAIL, env.E2E_SUPER_ADMIN_PASSWORD, env.E2E_SUPER_ADMIN_ID),
    admin: account(env.E2E_ADMIN_EMAIL, env.E2E_ADMIN_PASSWORD, env.E2E_ADMIN_ID),
    pm: account(env.E2E_PM_EMAIL, env.E2E_PM_PASSWORD, env.E2E_PM_ID),
    seniorDev: account(env.E2E_SENIOR_DEV_EMAIL, env.E2E_SENIOR_DEV_PASSWORD, env.E2E_SENIOR_DEV_ID),
    juniorDev: account(env.E2E_JUNIOR_DEV_EMAIL, env.E2E_JUNIOR_DEV_PASSWORD, env.E2E_JUNIOR_DEV_ID),
    qaTester: account(env.E2E_QA_TESTER_EMAIL, env.E2E_QA_TESTER_PASSWORD, env.E2E_QA_TESTER_ID),
    seniorQa: account(env.E2E_SENIOR_QA_EMAIL, env.E2E_SENIOR_QA_PASSWORD, env.E2E_SENIOR_QA_ID),
    qaLead: account(env.E2E_QA_LEAD_EMAIL, env.E2E_QA_LEAD_PASSWORD, env.E2E_QA_LEAD_ID),
  }),
});

export type E2EConfig = typeof cfg;
export type AccountKey = keyof E2EConfig["accounts"];
