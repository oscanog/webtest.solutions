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

const localFallbacks = {
  E2E_BASE_URL: "http://localhost",
  E2E_EMAIL: "e2e.local@webtest.test",
  E2E_PASSWORD: "E2E!Pass123",
  E2E_ORG_ID: "4",
  E2E_PROJECT_ID: "1",
  E2E_ASSIGNED_QA_LEAD_ID: "7",
  E2E_ASSIGNED_USER_ID: "9",
} as const;

const effectiveEnv = { ...process.env } as Record<string, string | undefined>;
function resolveEnvValue(
  key: keyof typeof localFallbacks,
  options?: { optional?: boolean }
): string | undefined {
  const current = effectiveEnv[key];
  if (!isBlank(current)) {
    return String(current).trim();
  }

  if (envName === "local") {
    return localFallbacks[key];
  }

  if (options?.optional) {
    return undefined;
  }

  return "";
}

const requiredTrimmedString = z.preprocess((value) => {
  if (isBlank(value)) {
    return "";
  }
  return String(value).trim();
}, z.string().min(1));

const requiredPositiveInt = z.preprocess((value) => {
  if (isBlank(value)) {
    return NaN;
  }
  return Number(String(value).trim());
}, z.number().int().positive());

const optionalPositiveInt = z.preprocess((value) => {
  if (isBlank(value)) {
    return undefined;
  }
  const numeric = Number(String(value).trim());
  if (!Number.isFinite(numeric) || numeric <= 0) {
    return undefined;
  }
  return numeric;
}, z.number().int().positive().optional());

const schema = z.object({
  E2E_BASE_URL: z.preprocess((value) => String(value ?? "").trim(), z.string().url()),
  E2E_EMAIL: requiredTrimmedString,
  E2E_PASSWORD: requiredTrimmedString,
  E2E_ORG_ID: requiredPositiveInt,
  E2E_PROJECT_ID: requiredPositiveInt,
  E2E_ASSIGNED_QA_LEAD_ID: optionalPositiveInt,
  E2E_ASSIGNED_USER_ID: optionalPositiveInt,
});

const envForValidation = {
  E2E_BASE_URL: resolveEnvValue("E2E_BASE_URL"),
  E2E_EMAIL: resolveEnvValue("E2E_EMAIL"),
  E2E_PASSWORD: resolveEnvValue("E2E_PASSWORD"),
  E2E_ORG_ID: resolveEnvValue("E2E_ORG_ID"),
  E2E_PROJECT_ID: resolveEnvValue("E2E_PROJECT_ID"),
  E2E_ASSIGNED_QA_LEAD_ID: resolveEnvValue("E2E_ASSIGNED_QA_LEAD_ID", { optional: true }),
  E2E_ASSIGNED_USER_ID: resolveEnvValue("E2E_ASSIGNED_USER_ID", { optional: true }),
};

const parsed = schema.safeParse(envForValidation);
if (!parsed.success) {
  const reasons = parsed.error.issues.map((issue) => {
    const field = issue.path.join(".") || "env";
    return `${field}: ${issue.message}`;
  });
  throw new Error(
    `Invalid E2E configuration for E2E_ENV=${envName} (${profilePath})\n${reasons.join("\n")}`
  );
}

const env = parsed.data;

export const cfg = Object.freeze({
  envName,
  baseUrl: env.E2E_BASE_URL,
  email: env.E2E_EMAIL,
  password: env.E2E_PASSWORD,
  orgId: env.E2E_ORG_ID,
  projectId: env.E2E_PROJECT_ID,
  assignedQaLeadId: env.E2E_ASSIGNED_QA_LEAD_ID ?? null,
  assignedUserId: env.E2E_ASSIGNED_USER_ID ?? null,
});

export type E2EConfig = typeof cfg;
