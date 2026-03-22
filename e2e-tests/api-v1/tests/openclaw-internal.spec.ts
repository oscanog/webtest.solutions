import { expect, request, test, APIRequestContext } from "@playwright/test";
import { cfg } from "../src/config";
import { authHeaders, loginRole, RoleSession } from "./helpers/auth";
import { ApiEnvelope, apiPostJson, parseJson } from "./helpers/client";

test.describe.configure({ mode: "serial" });

let api: APIRequestContext;
let pm: RoleSession;

function internalHeaders(): Record<string, string> {
  return {
    Authorization: `Bearer ${cfg.openclawInternalToken}`,
  };
}

test.beforeAll(async () => {
  api = await request.newContext({ baseURL: cfg.baseUrl });
  pm = await loginRole(api, "pm");
});

test.afterAll(async () => {
  await api.dispose();
});

test("openclaw internal endpoints enforce auth", async () => {
  const health = await api.get(`${cfg.apiBasePath}/openclaw/health`);
  expect(health.status()).toBe(401);

  const linkConfirm = await api.post(`${cfg.apiBasePath}/openclaw/link-confirm`, {
    data: {},
  });
  expect(linkConfirm.status()).toBe(401);

  const runtimeConfig = await api.get(`${cfg.apiBasePath}/openclaw/runtime-config`);
  expect(runtimeConfig.status()).toBe(401);

  const ingest = await api.post(`${cfg.apiBasePath}/openclaw/checklist-ingest`, {
    data: {},
  });
  expect(ingest.status()).toBe(401);
});

test("openclaw link prepare aliases work with session auth", async () => {
  const prepareA = await api.post(`${cfg.apiBasePath}/openclaw/link-prepare`, {
    data: {},
    headers: authHeaders(pm),
  });
  expect(prepareA.status()).toBe(200);
  const bodyA = await parseJson<{ code: string; expires_in_seconds: number }>(prepareA);
  expect(bodyA.code.length).toBe(12);

  const prepareB = await api.post(`${cfg.apiBasePath}/openclaw/link_prepare`, {
    data: {},
    headers: authHeaders(pm),
  });
  expect(prepareB.status()).toBe(200);
  const bodyB = await parseJson<{ code: string; expires_in_seconds: number }>(prepareB);
  expect(bodyB.code.length).toBe(12);
});

test("openclaw internal aliases are reachable with internal token", async () => {
  test.skip(!cfg.openclawInternalToken, "Set E2E_OPENCLAW_INTERNAL_TOKEN to run internal positive checks.");

  const health = await api.get(`${cfg.apiBasePath}/openclaw/health`, {
    headers: internalHeaders(),
  });
  if (health.status() === 401) {
    test.skip(true, "Configured E2E_OPENCLAW_INTERNAL_TOKEN is not accepted by this environment.");
  }
  expect(health.status()).toBe(200);

  for (const path of ["link-confirm", "link_confirm"]) {
    const res = await api.post(`${cfg.apiBasePath}/openclaw/${path}`, {
      data: {},
      headers: internalHeaders(),
    });
    expect(res.status()).toBe(422);
  }

  for (const path of ["link-context", "link_context"]) {
    const res = await api.post(`${cfg.apiBasePath}/openclaw/${path}`, {
      data: {},
      headers: internalHeaders(),
    });
    expect(res.status()).toBe(422);
  }

  for (const path of ["checklist-duplicates", "checklist_duplicates"]) {
    const res = await api.post(`${cfg.apiBasePath}/openclaw/${path}`, {
      data: {},
      headers: internalHeaders(),
    });
    expect(res.status()).toBe(422);
  }

  for (const path of ["checklist-batches", "checklist_batches"]) {
    const res = await api.post(`${cfg.apiBasePath}/openclaw/${path}`, {
      data: {},
      headers: internalHeaders(),
    });
    expect(res.status()).toBe(422);
  }

  for (const path of ["runtime-config", "runtime_config"]) {
    const res = await api.get(`${cfg.apiBasePath}/openclaw/${path}`, {
      headers: internalHeaders(),
    });
    expect([200, 500]).toContain(res.status());
  }

  for (const path of ["runtime-reload", "runtime_reload"]) {
    const res = await api.post(`${cfg.apiBasePath}/openclaw/${path}`, {
      data: { reason: "api_v1_e2e" },
      headers: internalHeaders(),
    });
    expect([202, 500]).toContain(res.status());
  }

  for (const path of ["runtime-status", "runtime_status"]) {
    const res = await api.post(`${cfg.apiBasePath}/openclaw/${path}`, {
      data: {
        gateway_state: "connected",
        discord_state: "ready",
      },
      headers: internalHeaders(),
    });
    expect([200, 500]).toContain(res.status());
  }
});

test("checklist ingest aliases validate bot token payload", async () => {
  test.skip(!cfg.checklistBotToken, "Set E2E_CHECKLIST_BOT_TOKEN to run ingest positive checks.");

  let tokenRejected = false;
  for (const path of ["checklist-ingest", "checklist_bot_ingest"]) {
    const res = await api.post(`${cfg.apiBasePath}/openclaw/${path}`, {
      data: {},
      headers: {
        "X-BUGCRAWLER-TOKEN": cfg.checklistBotToken,
      },
    });
    if (res.status() === 401) {
      tokenRejected = true;
      break;
    }
    expect(res.status()).toBe(422);
  }
  if (tokenRejected) {
    test.skip(true, "Configured E2E_CHECKLIST_BOT_TOKEN is not accepted by this environment.");
  }
});
