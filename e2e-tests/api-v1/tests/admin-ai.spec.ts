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

type AiRuntime = {
  is_enabled: boolean;
  default_provider_config_id: number | null;
  default_model_id: number | null;
  assistant_name: string;
  system_prompt: string;
};

let api: APIRequestContext;
let superAdmin: RoleSession;
let pm: RoleSession;

let createdProviderId = 0;
let createdModelId = 0;
let originalRuntime: AiRuntime | null = null;

function toInt(value: unknown): number {
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
}

async function restoreRuntime(): Promise<void> {
  if (!originalRuntime) {
    return;
  }

  await apiPutJson<ApiEnvelope<{ saved: boolean }>>(
    api,
    `${cfg.apiBasePath}/admin/ai/runtime`,
    originalRuntime,
    authHeaders(superAdmin)
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
      `${cfg.apiBasePath}/admin/ai/models/${createdModelId}`,
      undefined,
      authHeaders(superAdmin)
    );
  }
  if (createdProviderId > 0) {
    await apiDeleteJson<ApiEnvelope<{ deleted: boolean }>>(
      api,
      `${cfg.apiBasePath}/admin/ai/providers/${createdProviderId}`,
      undefined,
      authHeaders(superAdmin)
    );
  }

  await restoreRuntime();
  await api.dispose();
});

test("admin ai auth guards", async () => {
  const anonApi = await request.newContext({ baseURL: cfg.baseUrl });
  const anon = await apiGet<ApiEnvelope<unknown>>(anonApi, `${cfg.apiBasePath}/admin/ai/runtime`);
  expect(anon.res.status()).toBe(401);
  expect(anon.body.ok).toBe(false);
  await anonApi.dispose();

  const forbidden = await apiGet<ApiEnvelope<unknown>>(
    api,
    `${cfg.apiBasePath}/admin/ai/runtime`,
    authHeaders(pm)
  );
  expect(forbidden.res.status()).toBe(403);
  expect(forbidden.body.ok).toBe(false);
});

test("admin ai APIs work and remaining openclaw aliases stay limited", async () => {
  const runtime = await apiGet<ApiEnvelope<{ runtime: AiRuntime; providers: unknown[]; models: unknown[] }>>(
    api,
    `${cfg.apiBasePath}/admin/ai/runtime`,
    authHeaders(superAdmin)
  );
  expect(runtime.res.status()).toBe(200);
  expectApiSuccess(runtime.body);
  originalRuntime = runtime.body.data.runtime;

  const marker = Date.now();

  const savedRuntime = await apiPutJson<ApiEnvelope<{ saved: boolean; runtime: AiRuntime }>>(
    api,
    `${cfg.apiBasePath}/admin/ai/runtime`,
    {
      ...runtime.body.data.runtime,
      assistant_name: `BugCatcher AI ${marker}`,
    },
    authHeaders(superAdmin)
  );
  expect(savedRuntime.res.status()).toBe(200);
  expectApiSuccess(savedRuntime.body);
  expect(savedRuntime.body.data.runtime.assistant_name).toBe(`BugCatcher AI ${marker}`);

  const patchedRuntime = await apiPatchJson<ApiEnvelope<{ saved: boolean; runtime: AiRuntime }>>(
    api,
    `${cfg.apiBasePath}/admin/ai/runtime`,
    runtime.body.data.runtime,
    authHeaders(superAdmin)
  );
  expect(patchedRuntime.res.status()).toBe(200);
  expectApiSuccess(patchedRuntime.body);
  expect(patchedRuntime.body.data.runtime.assistant_name).toBe(runtime.body.data.runtime.assistant_name);

  const providersBefore = await apiGet<ApiEnvelope<{ providers: Array<{ id: number | string; provider_key: string }> }>>(
    api,
    `${cfg.apiBasePath}/admin/ai/providers`,
    authHeaders(superAdmin)
  );
  expect(providersBefore.res.status()).toBe(200);
  expectApiSuccess(providersBefore.body);

  const providerKey = `ai_e2e_${marker}`;
  const saveProvider = await apiPostJson<ApiEnvelope<{ saved: boolean; providers: Array<{ id: number | string; provider_key: string }> }>>(
    api,
    `${cfg.apiBasePath}/admin/ai/providers`,
    {
      provider_id: 0,
      provider_key: providerKey,
      display_name: `AI E2E Provider ${marker}`,
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

  const remoteModelId = `ai-e2e-model-${marker}`;
  const saveModel = await apiPostJson<ApiEnvelope<{ saved: boolean; models: Array<{ id: number | string; model_id: string }> }>>(
    api,
    `${cfg.apiBasePath}/admin/ai/models`,
    {
      provider_config_id: createdProviderId,
      model_id: 0,
      remote_model_id: remoteModelId,
      display_name: `AI E2E Model ${marker}`,
      supports_vision: true,
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

  const modelsList = await apiGet<ApiEnvelope<{ models: unknown[] }>>(
    api,
    `${cfg.apiBasePath}/admin/ai/models`,
    authHeaders(superAdmin)
  );
  expect(modelsList.res.status()).toBe(200);
  expectApiSuccess(modelsList.body);

  const aliasRuntime = await apiGet<ApiEnvelope<{ runtime: AiRuntime }>>(
    api,
    `${cfg.apiBasePath}/admin/openclaw/runtime`,
    authHeaders(superAdmin)
  );
  expect(aliasRuntime.res.status()).toBe(200);
  expectApiSuccess(aliasRuntime.body);

  const aliasProviders = await apiGet<ApiEnvelope<{ providers: unknown[] }>>(
    api,
    `${cfg.apiBasePath}/admin/openclaw/providers`,
    authHeaders(superAdmin)
  );
  expect(aliasProviders.res.status()).toBe(200);
  expectApiSuccess(aliasProviders.body);

  const aliasModels = await apiGet<ApiEnvelope<{ models: unknown[] }>>(
    api,
    `${cfg.apiBasePath}/admin/openclaw/models`,
    authHeaders(superAdmin)
  );
  expect(aliasModels.res.status()).toBe(200);
  expectApiSuccess(aliasModels.body);

  const removedReload = await apiPostJson<ApiEnvelope<unknown>>(
    api,
    `${cfg.apiBasePath}/admin/openclaw/runtime/reload`,
    { reason: "api_v1_e2e" },
    authHeaders(superAdmin)
  );
  expect(removedReload.res.status()).toBe(404);
  expect(removedReload.body.ok).toBe(false);

  const removedSnapshot = await apiPostJson<ApiEnvelope<unknown>>(
    api,
    `${cfg.apiBasePath}/admin/openclaw/snapshot`,
    {},
    authHeaders(superAdmin)
  );
  expect(removedSnapshot.res.status()).toBe(404);
  expect(removedSnapshot.body.ok).toBe(false);

  const removedChannels = await apiGet<ApiEnvelope<unknown>>(
    api,
    `${cfg.apiBasePath}/admin/openclaw/channels`,
    authHeaders(superAdmin)
  );
  expect(removedChannels.res.status()).toBe(404);
  expect(removedChannels.body.ok).toBe(false);

  const removedUsers = await apiGet<ApiEnvelope<unknown>>(
    api,
    `${cfg.apiBasePath}/admin/openclaw/users?limit=10`,
    authHeaders(superAdmin)
  );
  expect(removedUsers.res.status()).toBe(404);
  expect(removedUsers.body.ok).toBe(false);

  const removedRequests = await apiGet<ApiEnvelope<unknown>>(
    api,
    `${cfg.apiBasePath}/admin/openclaw/requests?limit=10`,
    authHeaders(superAdmin)
  );
  expect(removedRequests.res.status()).toBe(404);
  expect(removedRequests.body.ok).toBe(false);

  const deletedModel = await apiDeleteJson<ApiEnvelope<{ deleted: boolean; model_id: number }>>(
    api,
    `${cfg.apiBasePath}/admin/ai/models/${createdModelId}`,
    undefined,
    authHeaders(superAdmin)
  );
  expect(deletedModel.res.status()).toBe(200);
  expectApiSuccess(deletedModel.body);
  createdModelId = 0;

  const deletedProvider = await apiDeleteJson<ApiEnvelope<{ deleted: boolean; provider_id: number }>>(
    api,
    `${cfg.apiBasePath}/admin/ai/providers/${createdProviderId}`,
    undefined,
    authHeaders(superAdmin)
  );
  expect(deletedProvider.res.status()).toBe(200);
  expectApiSuccess(deletedProvider.body);
  createdProviderId = 0;
});
