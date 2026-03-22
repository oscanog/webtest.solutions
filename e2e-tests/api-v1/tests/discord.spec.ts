import { expect, request, test, APIRequestContext } from "@playwright/test";
import { cfg } from "../src/config";
import { authHeaders, loginRole, RoleSession } from "./helpers/auth";
import { ApiEnvelope, apiDeleteJson, apiGet, apiPostJson, expectApiSuccess } from "./helpers/client";

test.describe.configure({ mode: "serial" });

let api: APIRequestContext;
let pm: RoleSession;

test.beforeAll(async () => {
  api = await request.newContext({ baseURL: cfg.baseUrl });
  pm = await loginRole(api, "pm");
});

test.afterAll(async () => {
  await api.dispose();
});

test("discord link status, generate code, and unlink", async () => {
  const before = await apiGet<ApiEnvelope<{ link: Record<string, unknown> | null }>>(
    api,
    `${cfg.apiBasePath}/discord/link`,
    authHeaders(pm)
  );
  expect(before.res.status()).toBe(200);
  expectApiSuccess(before.body);

  const generated = await apiPostJson<ApiEnvelope<{ code: string; expires_in_seconds: number }>>(
    api,
    `${cfg.apiBasePath}/discord/link-code`,
    {},
    authHeaders(pm)
  );
  expect(generated.res.status()).toBe(200);
  expectApiSuccess(generated.body);
  expect(generated.body.data.code.length).toBe(12);
  expect(generated.body.data.expires_in_seconds).toBe(600);

  const removed = await apiDeleteJson<ApiEnvelope<{ unlinked: boolean }>>(
    api,
    `${cfg.apiBasePath}/discord/link`,
    undefined,
    authHeaders(pm)
  );
  expect(removed.res.status()).toBe(200);
  expectApiSuccess(removed.body);
  expect(removed.body.data.unlinked).toBe(true);
});
