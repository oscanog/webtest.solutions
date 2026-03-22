import { expect, request, test, APIRequestContext } from "@playwright/test";
import { cfg } from "../src/config";
import { authHeaders, loginRole, RoleSession } from "./helpers/auth";
import {
  ApiEnvelope,
  apiDeleteJson,
  apiGet,
  apiPatchJson,
  apiPostJson,
  apiPutJson,
  expectApiSuccess,
} from "./helpers/client";

test.describe.configure({ mode: "serial" });

let api: APIRequestContext;
let superAdmin: RoleSession;
let pm: RoleSession;

let createdProviderId = 0;
let createdModelId = 0;
let createdChannelId = 0;

let controlPlaneReady = true;

function toInt(value: unknown): number {
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
}

function controlPlaneMissing(body: ApiEnvelope<unknown>): boolean {
  if (body.ok) {
    return false;
  }
  const message = String(body.error.message || "").toLowerCase();
  const details = JSON.stringify(body.error.details ?? "").toLowerCase();
  return (
    message.includes("openclaw_control_plane_state") ||
    details.includes("openclaw_control_plane_state") ||
    message.includes("doesn't exist") ||
    details.includes("doesn't exist")
  );
}

test.beforeAll(async () => {
  api = await request.newContext({ baseURL: cfg.baseUrl });
  superAdmin = await loginRole(api, "superAdmin");
  pm = await loginRole(api, "pm");
});

test.afterAll(async () => {
  if (createdModelId > 0) {
    await apiDeleteJson<ApiEnvelope<{ deleted: boolean }>>(
      api,
      `${cfg.apiBasePath}/admin/openclaw/models/${createdModelId}`,
      undefined,
      authHeaders(superAdmin)
    );
  }
  if (createdChannelId > 0) {
    await apiDeleteJson<ApiEnvelope<{ deleted: boolean }>>(
      api,
      `${cfg.apiBasePath}/admin/openclaw/channels/${createdChannelId}`,
      undefined,
      authHeaders(superAdmin)
    );
  }
  if (createdProviderId > 0) {
    await apiDeleteJson<ApiEnvelope<{ deleted: boolean }>>(
      api,
      `${cfg.apiBasePath}/admin/openclaw/providers/${createdProviderId}`,
      undefined,
      authHeaders(superAdmin)
    );
  }

  await api.dispose();
});

test("admin openclaw auth guards", async () => {
  const anonApi = await request.newContext({ baseURL: cfg.baseUrl });
  const anon = await apiGet<ApiEnvelope<unknown>>(anonApi, `${cfg.apiBasePath}/admin/openclaw/runtime`);
  expect(anon.res.status()).toBe(401);
  expect(anon.body.ok).toBe(false);
  await anonApi.dispose();

  const forbidden = await apiGet<ApiEnvelope<unknown>>(
    api,
    `${cfg.apiBasePath}/admin/openclaw/runtime`,
    authHeaders(pm)
  );
  expect(forbidden.res.status()).toBe(403);
  expect(forbidden.body.ok).toBe(false);
});

test("admin openclaw full modular surface", async () => {
  const runtime = await apiGet<ApiEnvelope<{ runtime: unknown }>>(
    api,
    `${cfg.apiBasePath}/admin/openclaw/runtime`,
    authHeaders(superAdmin)
  );

  if (runtime.res.status() === 500 && controlPlaneMissing(runtime.body)) {
    controlPlaneReady = false;
    test.skip(true, "Local DB missing OpenClaw control-plane tables; skipping admin positive checks.");
  }

  expect(runtime.res.status()).toBe(200);
  expectApiSuccess(runtime.body);

  const savedRuntime = await apiPutJson<ApiEnvelope<{ saved: boolean }>>(
    api,
    `${cfg.apiBasePath}/admin/openclaw/runtime`,
    {
      is_enabled: false,
      discord_bot_token: "",
      default_provider_config_id: 0,
      default_model_id: 0,
      notes: "Saved by api-v1 e2e",
    },
    authHeaders(superAdmin)
  );
  expect(savedRuntime.res.status()).toBe(200);
  expectApiSuccess(savedRuntime.body);

  const patchedRuntime = await apiPatchJson<ApiEnvelope<{ saved: boolean }>>(
    api,
    `${cfg.apiBasePath}/admin/openclaw/runtime`,
    {
      is_enabled: false,
      notes: "Patched by api-v1 e2e",
    },
    authHeaders(superAdmin)
  );
  expect(patchedRuntime.res.status()).toBe(200);
  expectApiSuccess(patchedRuntime.body);

  const reload = await apiPostJson<ApiEnvelope<{ queued: boolean }>>(
    api,
    `${cfg.apiBasePath}/admin/openclaw/runtime/reload`,
    { reason: "api_v1_e2e_reload" },
    authHeaders(superAdmin)
  );
  expect(reload.res.status()).toBe(202);
  expectApiSuccess(reload.body);

  const snapshot = await apiPostJson<ApiEnvelope<{ snapshot: unknown }>>(
    api,
    `${cfg.apiBasePath}/admin/openclaw/snapshot`,
    {},
    authHeaders(superAdmin)
  );
  expect(snapshot.res.status()).toBe(200);
  expectApiSuccess(snapshot.body);

  const providersBefore = await apiGet<ApiEnvelope<{ providers: Array<{ id: number | string; provider_key: string }> }>>(
    api,
    `${cfg.apiBasePath}/admin/openclaw/providers`,
    authHeaders(superAdmin)
  );
  expect(providersBefore.res.status()).toBe(200);
  expectApiSuccess(providersBefore.body);

  const marker = Date.now();
  const providerKey = `e2e_${marker}`;

  const saveProvider = await apiPostJson<ApiEnvelope<{ saved: boolean; providers: Array<{ id: number | string; provider_key: string }> }>>(
    api,
    `${cfg.apiBasePath}/admin/openclaw/providers`,
    {
      provider_id: 0,
      provider_key: providerKey,
      display_name: `E2E Provider ${marker}`,
      provider_type: "openai-compatible",
      base_url: "https://example.invalid/v1",
      api_key: "test-key",
      is_enabled: true,
      supports_model_sync: false,
    },
    authHeaders(superAdmin)
  );
  expect(saveProvider.res.status()).toBe(200);
  expectApiSuccess(saveProvider.body);

  const provider = saveProvider.body.data.providers.find((row) => row.provider_key === providerKey);
  expect(provider).toBeTruthy();
  createdProviderId = toInt(provider?.id);
  expect(createdProviderId).toBeGreaterThan(0);

  const remoteModelId = `e2e-model-${marker}`;
  const saveModel = await apiPostJson<ApiEnvelope<{ saved: boolean; models: Array<{ id: number | string; model_id: string }> }>>(
    api,
    `${cfg.apiBasePath}/admin/openclaw/models`,
    {
      provider_config_id: createdProviderId,
      model_id: 0,
      remote_model_id: remoteModelId,
      display_name: `E2E Model ${marker}`,
      supports_vision: false,
      supports_json_output: true,
      is_enabled: true,
      is_default: false,
    },
    authHeaders(superAdmin)
  );
  expect(saveModel.res.status()).toBe(200);
  expectApiSuccess(saveModel.body);

  const model = saveModel.body.data.models.find((row) => String(row.model_id) === remoteModelId);
  expect(model).toBeTruthy();
  createdModelId = toInt(model?.id);
  expect(createdModelId).toBeGreaterThan(0);

  const saveChannel = await apiPostJson<ApiEnvelope<{ saved: boolean; channels: Array<{ id: number | string; channel_id: string }> }>>(
    api,
    `${cfg.apiBasePath}/admin/openclaw/channels`,
    {
      binding_id: 0,
      guild_id: `g-${marker}`,
      guild_name: `E2E Guild ${marker}`,
      channel_id: `c-${marker}`,
      channel_name: `e2e-${marker}`,
      is_enabled: true,
      allow_dm_followup: true,
    },
    authHeaders(superAdmin)
  );
  expect(saveChannel.res.status()).toBe(200);
  expectApiSuccess(saveChannel.body);

  const channel = saveChannel.body.data.channels.find((row) => row.channel_id === `c-${marker}`);
  expect(channel).toBeTruthy();
  createdChannelId = toInt(channel?.id);
  expect(createdChannelId).toBeGreaterThan(0);

  const users = await apiGet<ApiEnvelope<{ users: unknown[] }>>(
    api,
    `${cfg.apiBasePath}/admin/openclaw/users?limit=10`,
    authHeaders(superAdmin)
  );
  expect(users.res.status()).toBe(200);
  expectApiSuccess(users.body);

  const requests = await apiGet<ApiEnvelope<{ requests: unknown[] }>>(
    api,
    `${cfg.apiBasePath}/admin/openclaw/requests?limit=10`,
    authHeaders(superAdmin)
  );
  expect(requests.res.status()).toBe(200);
  expectApiSuccess(requests.body);

  const channelsList = await apiGet<ApiEnvelope<{ channels: unknown[] }>>(
    api,
    `${cfg.apiBasePath}/admin/openclaw/channels`,
    authHeaders(superAdmin)
  );
  expect(channelsList.res.status()).toBe(200);
  expectApiSuccess(channelsList.body);

  const modelsList = await apiGet<ApiEnvelope<{ models: unknown[] }>>(
    api,
    `${cfg.apiBasePath}/admin/openclaw/models`,
    authHeaders(superAdmin)
  );
  expect(modelsList.res.status()).toBe(200);
  expectApiSuccess(modelsList.body);

  const deletedModel = await apiDeleteJson<ApiEnvelope<{ deleted: boolean; model_id: number }>>(
    api,
    `${cfg.apiBasePath}/admin/openclaw/models/${createdModelId}`,
    undefined,
    authHeaders(superAdmin)
  );
  expect(deletedModel.res.status()).toBe(200);
  expectApiSuccess(deletedModel.body);
  createdModelId = 0;

  const deletedChannel = await apiDeleteJson<ApiEnvelope<{ deleted: boolean; binding_id: number }>>(
    api,
    `${cfg.apiBasePath}/admin/openclaw/channels/${createdChannelId}`,
    undefined,
    authHeaders(superAdmin)
  );
  expect(deletedChannel.res.status()).toBe(200);
  expectApiSuccess(deletedChannel.body);
  createdChannelId = 0;

  const deletedProvider = await apiDeleteJson<ApiEnvelope<{ deleted: boolean; provider_id: number }>>(
    api,
    `${cfg.apiBasePath}/admin/openclaw/providers/${createdProviderId}`,
    undefined,
    authHeaders(superAdmin)
  );
  expect(deletedProvider.res.status()).toBe(200);
  expectApiSuccess(deletedProvider.body);
  createdProviderId = 0;

  expect(controlPlaneReady).toBe(true);
});
