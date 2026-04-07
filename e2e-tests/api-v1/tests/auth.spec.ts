import { expect, request, test, APIRequestContext } from "@playwright/test";
import { cfg } from "../src/config";
import { authHeaders, loginRole } from "./helpers/auth";
import {
  ApiEnvelope,
  apiGet,
  apiPostJson,
  apiPutJson,
  expectApiSuccess,
} from "./helpers/client";

test.describe.configure({ mode: "serial" });

type HealthResponse = { status: string };
type LoginResponse = {
  active_org_id: number;
  tokens: {
    access_token: string;
    refresh_token: string;
  };
};
type MeResponse = {
  user: { id: number; email: string };
  memberships: Array<{ org_id: number; role: string }>;
};

let api: APIRequestContext;

test.beforeAll(async () => {
  api = await request.newContext({ baseURL: cfg.baseUrl });
});

test.afterAll(async () => {
  await api.dispose();
});

test("root and health endpoints are reachable", async () => {
  const root = await apiGet<ApiEnvelope<{ name: string; version: string; status: string }>>(
    api,
    `${cfg.apiBasePath}`
  );
  expect(root.res.status()).toBe(200);
  expectApiSuccess(root.body);
  expect(root.body.data.version).toBe("v1");

  const health = await apiGet<ApiEnvelope<HealthResponse>>(api, `${cfg.apiBasePath}/health`);
  expect(health.res.status()).toBe(200);
  expectApiSuccess(health.body);
  expect(health.body.data.status).toBe("ok");
});

test("login, me, refresh, active-org, logout", async () => {
  const pm = await loginRole(api, "pm");

  const me = await apiGet<ApiEnvelope<MeResponse>>(
    api,
    `${cfg.apiBasePath}/auth/me`,
    authHeaders(pm)
  );
  expect(me.res.status()).toBe(200);
  expectApiSuccess(me.body);
  expect(me.body.data.user.email).toBe(cfg.accounts.pm.email);

  const refresh = await apiPostJson<ApiEnvelope<{ active_org_id: number; tokens: LoginResponse["tokens"] }>>(
    api,
    `${cfg.apiBasePath}/auth/refresh`,
    {
      refresh_token: pm.refreshToken,
    }
  );
  expect(refresh.res.status()).toBe(200);
  expectApiSuccess(refresh.body);
  expect(refresh.body.data.tokens.access_token.length).toBeGreaterThan(20);

  const activeOrg = await apiPutJson<ApiEnvelope<{ active_org_id: number }>>(
    api,
    `${cfg.apiBasePath}/session/active-org`,
    { org_id: cfg.orgId },
    authHeaders(pm)
  );
  expect(activeOrg.res.status()).toBe(200);
  expectApiSuccess(activeOrg.body);
  expect(activeOrg.body.data.active_org_id).toBe(cfg.orgId);

  const logout = await apiPostJson<ApiEnvelope<{ logged_out: boolean }>>(
    api,
    `${cfg.apiBasePath}/auth/logout`,
    {},
    authHeaders(pm)
  );
  expect(logout.res.status()).toBe(200);
  expectApiSuccess(logout.body);
  expect(logout.body.data.logged_out).toBe(true);
});

test("signup then login works for a new account", async () => {
  const marker = Date.now();
  const email = `api.v1.signup.${marker}@webtest.test`;
  const password = `E2E!Signup${marker}`;

  const signup = await apiPostJson<
    ApiEnvelope<{ created: boolean; user_id: number; message: string }>
  >(api, `${cfg.apiBasePath}/auth/signup`, {
    username: `signup_${marker}`,
    email,
    password,
    confirm_password: password,
  });
  expect(signup.res.status()).toBe(201);
  expectApiSuccess(signup.body);

  const login = await apiPostJson<ApiEnvelope<LoginResponse>>(api, `${cfg.apiBasePath}/auth/login`, {
    email,
    password,
  });
  expect(login.res.status()).toBe(200);
  expectApiSuccess(login.body);
  expect(login.body.data.tokens.access_token.length).toBeGreaterThan(20);

  const me = await apiGet<ApiEnvelope<MeResponse>>(
    api,
    `${cfg.apiBasePath}/auth/me`,
    { Authorization: `Bearer ${login.body.data.tokens.access_token}` }
  );
  expect(me.res.status()).toBe(200);
  expectApiSuccess(me.body);
  expect(me.body.data.user.email).toBe(email);
});

test("forgot-password endpoints validate payloads", async () => {
  const requestOtp = await apiPostJson<ApiEnvelope<unknown>>(
    api,
    `${cfg.apiBasePath}/auth/forgot/request-otp`,
    {}
  );
  expect(requestOtp.res.status()).toBe(422);
  expect(requestOtp.body.ok).toBe(false);

  const resendOtp = await apiPostJson<ApiEnvelope<unknown>>(
    api,
    `${cfg.apiBasePath}/auth/forgot/resend-otp`,
    {}
  );
  expect(resendOtp.res.status()).toBe(422);
  expect(resendOtp.body.ok).toBe(false);

  const verifyOtp = await apiPostJson<ApiEnvelope<unknown>>(
    api,
    `${cfg.apiBasePath}/auth/forgot/verify-otp`,
    {}
  );
  expect(verifyOtp.res.status()).toBe(422);
  expect(verifyOtp.body.ok).toBe(false);

  const resetPassword = await apiPostJson<ApiEnvelope<unknown>>(
    api,
    `${cfg.apiBasePath}/auth/forgot/reset-password`,
    {}
  );
  expect(resetPassword.res.status()).toBe(422);
  expect(resetPassword.body.ok).toBe(false);
});

test("auth guard rejects anonymous /auth/me", async () => {
  const anon = await request.newContext({ baseURL: cfg.baseUrl });
  const me = await apiGet<ApiEnvelope<unknown>>(anon, `${cfg.apiBasePath}/auth/me`);
  expect(me.res.status()).toBe(401);
  expect(me.body.ok).toBe(false);
  await anon.dispose();
});
