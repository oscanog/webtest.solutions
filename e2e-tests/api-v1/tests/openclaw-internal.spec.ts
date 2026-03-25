import { expect, request, test, APIRequestContext } from "@playwright/test";
import { cfg } from "../src/config";
import { authHeaders, loginRole, RoleSession } from "./helpers/auth";

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

test("removed legacy bridge endpoints now return not found", async () => {
  const anonHealth = await api.get(`${cfg.apiBasePath}/openclaw/health`);
  expect(anonHealth.status()).toBe(404);

  for (const path of ["link-prepare", "link_prepare"]) {
    const res = await api.post(`${cfg.apiBasePath}/openclaw/${path}`, {
      data: {},
      headers: authHeaders(pm),
    });
    expect(res.status()).toBe(404);
  }

  for (const path of ["link-confirm", "link_confirm", "link-context", "link_context"]) {
    const res = await api.post(`${cfg.apiBasePath}/openclaw/${path}`, {
      data: {},
      headers: internalHeaders(),
    });
    expect(res.status()).toBe(404);
  }

  for (const path of ["runtime-config", "runtime_config"]) {
    const res = await api.get(`${cfg.apiBasePath}/openclaw/${path}`, {
      headers: internalHeaders(),
    });
    expect(res.status()).toBe(404);
  }

  for (const path of ["runtime-reload", "runtime_reload", "runtime-status", "runtime_status"]) {
    const res = await api.post(`${cfg.apiBasePath}/openclaw/${path}`, {
      data: {},
      headers: internalHeaders(),
    });
    expect(res.status()).toBe(404);
  }
});

test("retained openclaw checklist aliases still enforce auth", async () => {
  for (const path of ["checklist-duplicates", "checklist_duplicates"]) {
    const res = await api.post(`${cfg.apiBasePath}/openclaw/${path}`, {
      data: {},
    });
    expect(res.status()).toBe(401);
  }

  for (const path of ["checklist-batches", "checklist_batches"]) {
    const res = await api.post(`${cfg.apiBasePath}/openclaw/${path}`, {
      data: {},
    });
    expect(res.status()).toBe(401);
  }

  const ingest = await api.post(`${cfg.apiBasePath}/openclaw/checklist-ingest`, {
    data: {},
  });
  expect(ingest.status()).toBe(401);
});

test("retained openclaw checklist aliases still validate payloads", async () => {
  test.skip(!cfg.openclawInternalToken, "Set E2E_OPENCLAW_INTERNAL_TOKEN to run internal positive checks.");

  let internalTokenRejected = false;
  for (const path of ["checklist-duplicates", "checklist_duplicates"]) {
    const res = await api.post(`${cfg.apiBasePath}/openclaw/${path}`, {
      data: {},
      headers: internalHeaders(),
    });
    if (res.status() === 401) {
      internalTokenRejected = true;
      break;
    }
    expect(res.status()).toBe(422);
  }
  if (internalTokenRejected) {
    test.skip(true, "Configured E2E_OPENCLAW_INTERNAL_TOKEN is not accepted by this environment.");
  }

  for (const path of ["checklist-batches", "checklist_batches"]) {
    const res = await api.post(`${cfg.apiBasePath}/openclaw/${path}`, {
      data: {},
      headers: internalHeaders(),
    });
    expect(res.status()).toBe(422);
  }

  test.skip(!cfg.checklistBotToken, "Set E2E_CHECKLIST_BOT_TOKEN to run ingest positive checks.");

  let botTokenRejected = false;
  for (const path of ["checklist-ingest", "checklist_bot_ingest"]) {
    const res = await api.post(`${cfg.apiBasePath}/openclaw/${path}`, {
      data: {},
      headers: {
        "X-BUGCRAWLER-TOKEN": cfg.checklistBotToken,
      },
    });
    if (res.status() === 401) {
      botTokenRejected = true;
      break;
    }
    expect(res.status()).toBe(422);
  }
  if (botTokenRejected) {
    test.skip(true, "Configured E2E_CHECKLIST_BOT_TOKEN is not accepted by this environment.");
  }
});
