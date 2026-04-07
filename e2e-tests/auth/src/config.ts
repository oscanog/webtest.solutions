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

function normalizeBaseUrl(value: string): string {
  return `${value.replace(/\/+$/, "")}/`;
}

const localFallbacks = {
  E2E_BASE_URL: "http://localhost/webtest",
  E2E_AUTH_EMAIL: "admin@local.dev",
  E2E_AUTH_PASSWORD: "DevPass123!",
  E2E_RESET_EMAIL: "admin@local.dev",
  E2E_RESET_ORIGINAL_PASSWORD: "DevPass123!",
  E2E_RESET_NEW_PASSWORD: "DevPass123!Reset1",
  E2E_IMAP_HOST: "",
  E2E_IMAP_PORT: "",
  E2E_IMAP_USER: "",
  E2E_IMAP_PASSWORD: "",
} as const;

const effectiveEnv = { ...process.env } as Record<string, string | undefined>;

function resolveEnvValue(key: keyof typeof localFallbacks): string {
  const current = effectiveEnv[key];
  if (!isBlank(current)) {
    return String(current).trim();
  }

  if (envName === "local") {
    return localFallbacks[key];
  }

  return "";
}

const requiredUrl = z.preprocess((value) => String(value ?? "").trim(), z.string().url());
const optionalTrimmedString = z.preprocess((value) => {
  if (isBlank(value)) {
    return "";
  }

  return String(value).trim();
}, z.string());

const optionalPositiveInt = z.preprocess((value) => {
  if (isBlank(value)) {
    return undefined;
  }

  return Number(String(value).trim());
}, z.number().int().positive().optional());

const schema = z.object({
  E2E_BASE_URL: requiredUrl,
  E2E_AUTH_EMAIL: optionalTrimmedString,
  E2E_AUTH_PASSWORD: optionalTrimmedString,
  E2E_RESET_EMAIL: optionalTrimmedString,
  E2E_RESET_ORIGINAL_PASSWORD: optionalTrimmedString,
  E2E_RESET_NEW_PASSWORD: optionalTrimmedString,
  E2E_IMAP_HOST: optionalTrimmedString,
  E2E_IMAP_PORT: optionalPositiveInt,
  E2E_IMAP_USER: optionalTrimmedString,
  E2E_IMAP_PASSWORD: optionalTrimmedString,
});

const parsed = schema.safeParse({
  E2E_BASE_URL: resolveEnvValue("E2E_BASE_URL"),
  E2E_AUTH_EMAIL: resolveEnvValue("E2E_AUTH_EMAIL"),
  E2E_AUTH_PASSWORD: resolveEnvValue("E2E_AUTH_PASSWORD"),
  E2E_RESET_EMAIL: resolveEnvValue("E2E_RESET_EMAIL"),
  E2E_RESET_ORIGINAL_PASSWORD: resolveEnvValue("E2E_RESET_ORIGINAL_PASSWORD"),
  E2E_RESET_NEW_PASSWORD: resolveEnvValue("E2E_RESET_NEW_PASSWORD"),
  E2E_IMAP_HOST: resolveEnvValue("E2E_IMAP_HOST"),
  E2E_IMAP_PORT: resolveEnvValue("E2E_IMAP_PORT"),
  E2E_IMAP_USER: resolveEnvValue("E2E_IMAP_USER"),
  E2E_IMAP_PASSWORD: resolveEnvValue("E2E_IMAP_PASSWORD"),
});

if (!parsed.success) {
  const reasons = parsed.error.issues.map((issue) => {
    const field = issue.path.join(".") || "env";
    return `${field}: ${issue.message}`;
  });
  throw new Error(
    `Invalid auth E2E configuration for E2E_ENV=${envName} (${profilePath})\n${reasons.join("\n")}`
  );
}

const env = parsed.data;
const issues: string[] = [];

function hasPair(first: string, second: string): boolean {
  return first !== "" && second !== "";
}

function hasQuad(a: string, b: string, c: string, d: string): boolean {
  return a !== "" && b !== "" && c !== "" && d !== "";
}

if ((env.E2E_AUTH_EMAIL === "") !== (env.E2E_AUTH_PASSWORD === "")) {
  issues.push("E2E_AUTH_EMAIL and E2E_AUTH_PASSWORD must both be set or both be blank.");
}

if (
  [env.E2E_RESET_EMAIL, env.E2E_RESET_ORIGINAL_PASSWORD, env.E2E_RESET_NEW_PASSWORD].filter(
    (value) => value !== ""
  ).length > 0 &&
  !(env.E2E_RESET_EMAIL !== "" && env.E2E_RESET_ORIGINAL_PASSWORD !== "" && env.E2E_RESET_NEW_PASSWORD !== "")
) {
  issues.push(
    "E2E_RESET_EMAIL, E2E_RESET_ORIGINAL_PASSWORD, and E2E_RESET_NEW_PASSWORD must all be set together."
  );
}

if (
  [env.E2E_IMAP_HOST, env.E2E_IMAP_PORT, env.E2E_IMAP_USER, env.E2E_IMAP_PASSWORD].filter(
    (value) => value !== "" && value !== undefined
  ).length > 0 &&
  !hasQuad(
    env.E2E_IMAP_HOST,
    String(env.E2E_IMAP_PORT ?? ""),
    env.E2E_IMAP_USER,
    env.E2E_IMAP_PASSWORD
  )
) {
  issues.push(
    "E2E_IMAP_HOST, E2E_IMAP_PORT, E2E_IMAP_USER, and E2E_IMAP_PASSWORD must all be set together."
  );
}

if (issues.length > 0) {
  throw new Error(
    `Invalid auth E2E configuration for E2E_ENV=${envName} (${profilePath})\n${issues.join("\n")}`
  );
}

export const cfg = Object.freeze({
  envName,
  isProduction: envName === "production",
  baseUrl: normalizeBaseUrl(env.E2E_BASE_URL),
  authEmail: env.E2E_AUTH_EMAIL,
  authPassword: env.E2E_AUTH_PASSWORD,
  resetEmail: env.E2E_RESET_EMAIL,
  resetOriginalPassword: env.E2E_RESET_ORIGINAL_PASSWORD,
  resetNewPassword: env.E2E_RESET_NEW_PASSWORD,
  imapHost: env.E2E_IMAP_HOST,
  imapPort: env.E2E_IMAP_PORT ?? 0,
  imapUser: env.E2E_IMAP_USER,
  imapPassword: env.E2E_IMAP_PASSWORD,
  hasAuthCredentials: hasPair(env.E2E_AUTH_EMAIL, env.E2E_AUTH_PASSWORD),
  hasResetCredentials:
    env.E2E_RESET_EMAIL !== "" &&
    env.E2E_RESET_ORIGINAL_PASSWORD !== "" &&
    env.E2E_RESET_NEW_PASSWORD !== "",
  hasImapCredentials: hasQuad(
    env.E2E_IMAP_HOST,
    String(env.E2E_IMAP_PORT ?? ""),
    env.E2E_IMAP_USER,
    env.E2E_IMAP_PASSWORD
  ),
});

export type E2EConfig = typeof cfg;
