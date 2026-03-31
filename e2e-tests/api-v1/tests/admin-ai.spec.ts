import fs from "node:fs";
import path from "node:path";
import { expect, request, test, APIRequestContext } from "@playwright/test";
import { cfg } from "../src/config";
import { authHeaders, loginRole, RoleSession } from "./helpers/auth";
import {
  ApiEnvelope,
  apiDeleteJson,
  apiGet,
  apiPatchJson,
  apiPostJson,
  apiPostMultipart,
  apiPutJson,
  expectApiSuccess,
} from "./helpers/client";

test.describe.configure({ mode: "serial" });

type AiRuntime = {
  is_enabled: boolean;
  default_provider_config_id: number | null;
  default_model_id: number | null;
  assistant_name: string;
  system_prompt: string;
};

type AiPersona = {
  id?: number;
  persona_key: string;
  display_name: string;
  is_enabled: boolean;
  provider_config_id: number | null;
  provider_name?: string;
  model_id: number | null;
  model_name?: string;
  supports_vision?: boolean;
  assistant_name: string;
  system_prompt: string;
};

type AiReadiness = {
  link: {
    enabled: boolean;
    warning_message: string;
  };
  screenshot: {
    enabled: boolean;
    warning_message: string;
  };
};

type AiRuntimeSnapshot = {
  runtime: AiRuntime;
  providers: Array<{ id: number | string; provider_key: string; provider_type?: string }>;
  models: Array<{ id: number | string; model_id: string; supports_vision: boolean }>;
  personas: AiPersona[];
  readiness: AiReadiness;
};

type DraftContext = {
  source_mode: "screenshot" | "link";
  target_mode: "new" | "existing";
  existing_batch_id: number | null;
  page_url: string;
  page_link_status: string;
  page_link_warning: string;
  has_saved_link_credentials: boolean;
  is_ready: boolean;
};

type GeneratedItem = {
  id: number;
  title: string;
  page_url: string;
  source_mode: "screenshot" | "link";
  review_status: string;
};

type AiMessage = {
  id: number;
  role: "user" | "assistant" | "system";
  content: string;
  generated_checklist_items?: GeneratedItem[];
};

type AiThread = {
  id: number;
  title: string;
  draft_context: DraftContext;
  messages: AiMessage[];
};

type PageLinkPreview = {
  page_url: string;
  status: string;
  page_title: string;
  excerpt: string;
  warning_message: string;
  requires_credentials: boolean;
  credentials_saved: boolean;
};

type Batch = {
  id: number;
  project_id: number;
  title: string;
  page_url: string | null;
};

type AiBootstrap = {
  enabled: boolean;
  assistant_name: string;
  error_message?: string;
  source_modes: {
    link: {
      enabled: boolean;
      warning_message: string;
    };
    screenshot: {
      enabled: boolean;
      warning_message: string;
    };
  };
  personas: Array<{
    persona_key: string;
    display_name: string;
    is_enabled: boolean;
    provider_name: string;
    model_name: string;
    supports_vision: boolean;
  }>;
  org_id: number;
};

let api: APIRequestContext;
let superAdmin: RoleSession;
let pm: RoleSession;
let qaLead: RoleSession;

let createdProviderId = 0;
let createdTextModelId = 0;
let createdVisionModelId = 0;
let originalRuntime: AiRuntime | null = null;
let originalPersonas: AiPersona[] = [];

const createdBatchIds: number[] = [];
const createdThreadIds: number[] = [];

const screenshotFixturePath = path.resolve(__dirname, "fixtures", "sample.png");

function toInt(value: unknown): number {
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
}

function appUrl(relativePath: string): string {
  const appBasePath = cfg.apiBasePath.replace(/\/api\/v1\/?$/, "");
  const normalizedPath = relativePath.startsWith("/") ? relativePath : `/${relativePath}`;
  return `${cfg.baseUrl}${appBasePath}${normalizedPath}`;
}

function previewFixtureUrl(fileName: string): string {
  return appUrl(`/e2e-fixtures/ai-chat/${fileName}`);
}

function personaPayload(personas: AiPersona[]): Record<string, Partial<AiPersona>> {
  const payload: Record<string, Partial<AiPersona>> = {};
  for (const persona of personas) {
    payload[persona.persona_key] = {
      is_enabled: persona.is_enabled,
      provider_config_id: persona.provider_config_id,
      model_id: persona.model_id,
      assistant_name: persona.assistant_name,
      system_prompt: persona.system_prompt,
    };
  }
  return payload;
}

function findPersona(personas: AiPersona[], personaKey: string): AiPersona {
  const persona = personas.find((row) => row.persona_key === personaKey);
  expect(persona, `Expected persona ${personaKey} to exist`).toBeTruthy();
  return persona as AiPersona;
}

function lastAssistantMessage(thread: AiThread): AiMessage {
  const message = [...thread.messages].reverse().find((row) => row.role === "assistant");
  expect(message, "Expected an assistant message").toBeTruthy();
  return message as AiMessage;
}

async function saveRuntimeConfig(
  runtime: AiRuntime,
  personas: Record<string, Partial<AiPersona>>
): Promise<AiRuntimeSnapshot> {
  const saved = await apiPutJson<ApiEnvelope<AiRuntimeSnapshot & { saved: boolean }>>(
    api,
    `${cfg.apiBasePath}/admin/ai/runtime`,
    {
      ...runtime,
      personas,
    },
    authHeaders(superAdmin)
  );
  expect(saved.res.status()).toBe(200);
  expectApiSuccess(saved.body);
  return saved.body.data;
}

async function restoreRuntime(): Promise<void> {
  if (!originalRuntime) {
    return;
  }

  await saveRuntimeConfig(originalRuntime, personaPayload(originalPersonas));
}

async function createMockRuntimeAssets(marker: number): Promise<void> {
  if (createdProviderId > 0 && createdTextModelId > 0 && createdVisionModelId > 0) {
    return;
  }

  const providerKey = `mock_ai_e2e_${marker}`;
  const saveProvider = await apiPostJson<ApiEnvelope<{ saved: boolean; providers: Array<{ id: number | string; provider_key: string }> }>>(
    api,
    `${cfg.apiBasePath}/admin/ai/providers`,
    {
      provider_id: 0,
      provider_key: providerKey,
      display_name: `Mock AI E2E Provider ${marker}`,
      provider_type: "mock",
      base_url: "https://example.invalid/mock",
      api_key: "mock-secret",
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

  const textModelRemoteId = `mock-text-${marker}`;
  const saveTextModel = await apiPostJson<ApiEnvelope<{ saved: boolean; models: Array<{ id: number | string; model_id: string }> }>>(
    api,
    `${cfg.apiBasePath}/admin/ai/models`,
    {
      provider_config_id: createdProviderId,
      model_id: 0,
      remote_model_id: textModelRemoteId,
      display_name: `Mock Text Model ${marker}`,
      supports_vision: false,
      supports_json_output: true,
      is_enabled: true,
      is_default: false,
    },
    authHeaders(superAdmin)
  );
  expect(saveTextModel.res.status()).toBe(200);
  expectApiSuccess(saveTextModel.body);

  const textModel = saveTextModel.body.data.models.find((row) => String(row.model_id) === textModelRemoteId);
  expect(textModel).toBeTruthy();
  createdTextModelId = toInt(textModel?.id);
  expect(createdTextModelId).toBeGreaterThan(0);

  const visionModelRemoteId = `mock-vision-${marker}`;
  const saveVisionModel = await apiPostJson<ApiEnvelope<{ saved: boolean; models: Array<{ id: number | string; model_id: string }> }>>(
    api,
    `${cfg.apiBasePath}/admin/ai/models`,
    {
      provider_config_id: createdProviderId,
      model_id: 0,
      remote_model_id: visionModelRemoteId,
      display_name: `Mock Vision Model ${marker}`,
      supports_vision: true,
      supports_json_output: true,
      is_enabled: true,
      is_default: false,
    },
    authHeaders(superAdmin)
  );
  expect(saveVisionModel.res.status()).toBe(200);
  expectApiSuccess(saveVisionModel.body);

  const visionModel = saveVisionModel.body.data.models.find((row) => String(row.model_id) === visionModelRemoteId);
  expect(visionModel).toBeTruthy();
  createdVisionModelId = toInt(visionModel?.id);
  expect(createdVisionModelId).toBeGreaterThan(0);
}

async function createThread(title: string): Promise<AiThread> {
  const created = await apiPostJson<ApiEnvelope<{ thread: AiThread }>>(
    api,
    `${cfg.apiBasePath}/ai-chat/threads`,
    { title },
    authHeaders(qaLead)
  );
  expect(created.res.status()).toBe(201);
  expectApiSuccess(created.body);
  createdThreadIds.push(created.body.data.thread.id);
  return created.body.data.thread;
}

async function updateDraftContext(threadId: number, payload: Record<string, unknown>): Promise<AiThread> {
  const updated = await apiPatchJson<ApiEnvelope<{ thread: AiThread }>>(
    api,
    `${cfg.apiBasePath}/ai-chat/threads/${threadId}/draft-context`,
    payload,
    authHeaders(qaLead)
  );
  expect(updated.res.status()).toBe(200);
  expectApiSuccess(updated.body);
  return updated.body.data.thread;
}

async function previewPageLink(
  threadId: number,
  payload: Record<string, unknown>
): Promise<{ thread: AiThread; page_link_preview: PageLinkPreview }> {
  const preview = await apiPostJson<ApiEnvelope<{ thread: AiThread; page_link_preview: PageLinkPreview }>>(
    api,
    `${cfg.apiBasePath}/ai-chat/threads/${threadId}/page-link-preview`,
    payload,
    authHeaders(qaLead)
  );
  expect(preview.res.status()).toBe(200);
  expectApiSuccess(preview.body);
  return preview.body.data;
}

async function generateChecklistDraft(
  threadId: number,
  message: string
): Promise<{ thread: AiThread; assistant_message_id: number }> {
  const drafted = await apiPostJson<ApiEnvelope<{ thread: AiThread; assistant_message_id: number }>>(
    api,
    `${cfg.apiBasePath}/ai-chat/threads/${threadId}/checklist-drafts`,
    { message },
    authHeaders(qaLead)
  );
  expect(drafted.res.status()).toBe(201);
  expectApiSuccess(drafted.body);
  return drafted.body.data;
}

async function generateChecklistDraftWithScreenshot(
  threadId: number,
  message: string
): Promise<{ thread: AiThread; assistant_message_id: number }> {
  const drafted = await apiPostMultipart<ApiEnvelope<{ thread: AiThread; assistant_message_id: number }>>(
    api,
    `${cfg.apiBasePath}/ai-chat/threads/${threadId}/checklist-drafts`,
    {
      message,
      "attachments[]": {
        name: "sample.png",
        mimeType: "image/png",
        buffer: fs.readFileSync(screenshotFixturePath),
      },
    },
    authHeaders(qaLead)
  );
  expect(drafted.res.status()).toBe(201);
  expectApiSuccess(drafted.body);
  return drafted.body.data;
}

async function createBatchFixture(marker: number, pageUrl: string): Promise<number> {
  const created = await apiPostJson<ApiEnvelope<{ batch: Batch }>>(
    api,
    `${cfg.apiBasePath}/checklist/batches`,
    {
      project_id: cfg.projectId,
      title: `AI Chat Fixture Batch ${marker}`,
      module_name: "AI Chat",
      submodule_name: "Drafts",
      status: "open",
      assigned_qa_lead_id: qaLead.userId,
      notes: "Created by admin-ai API v1 tests",
      page_url: pageUrl,
    },
    authHeaders(pm)
  );
  expect(created.res.status()).toBe(201);
  expectApiSuccess(created.body);
  createdBatchIds.push(created.body.data.batch.id);
  return created.body.data.batch.id;
}

async function fetchBatch(batchId: number): Promise<Batch> {
  const fetched = await apiGet<ApiEnvelope<{ batch: Batch }>>(
    api,
    `${cfg.apiBasePath}/checklist/batch?id=${batchId}`,
    authHeaders(pm)
  );
  expect(fetched.res.status()).toBe(200);
  expectApiSuccess(fetched.body);
  return fetched.body.data.batch;
}

test.beforeAll(async () => {
  api = await request.newContext({ baseURL: cfg.baseUrl });
  superAdmin = await loginRole(api, "superAdmin");
  pm = await loginRole(api, "pm");
  qaLead = await loginRole(api, "qaLead");
});

test.afterAll(async () => {
  for (const threadId of [...createdThreadIds].reverse()) {
    await apiDeleteJson<ApiEnvelope<{ deleted: boolean }>>(
      api,
      `${cfg.apiBasePath}/ai-chat/threads/${threadId}`,
      undefined,
      authHeaders(qaLead)
    );
  }

  for (const batchId of [...createdBatchIds].reverse()) {
    await apiDeleteJson<ApiEnvelope<{ deleted: boolean; id: number }>>(
      api,
      `${cfg.apiBasePath}/checklist/batch?id=${batchId}`,
      undefined,
      authHeaders(pm)
    );
  }

  await restoreRuntime();

  if (createdVisionModelId > 0) {
    await apiDeleteJson<ApiEnvelope<{ deleted: boolean; model_id: number }>>(
      api,
      `${cfg.apiBasePath}/admin/ai/models/${createdVisionModelId}`,
      undefined,
      authHeaders(superAdmin)
    );
  }
  if (createdTextModelId > 0) {
    await apiDeleteJson<ApiEnvelope<{ deleted: boolean; model_id: number }>>(
      api,
      `${cfg.apiBasePath}/admin/ai/models/${createdTextModelId}`,
      undefined,
      authHeaders(superAdmin)
    );
  }
  if (createdProviderId > 0) {
    await apiDeleteJson<ApiEnvelope<{ deleted: boolean; provider_id: number }>>(
      api,
      `${cfg.apiBasePath}/admin/ai/providers/${createdProviderId}`,
      undefined,
      authHeaders(superAdmin)
    );
  }

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

test("admin ai APIs expose personas, readiness, and keep openclaw aliases limited", async () => {
  const runtime = await apiGet<ApiEnvelope<AiRuntimeSnapshot>>(
    api,
    `${cfg.apiBasePath}/admin/ai/runtime`,
    authHeaders(superAdmin)
  );
  expect(runtime.res.status()).toBe(200);
  expectApiSuccess(runtime.body);
  originalRuntime = runtime.body.data.runtime;
  originalPersonas = runtime.body.data.personas;

  const marker = Date.now();
  await createMockRuntimeAssets(marker);

  const textOnlySnapshot = await saveRuntimeConfig(
    {
      ...runtime.body.data.runtime,
      default_provider_config_id: createdProviderId,
      default_model_id: createdTextModelId,
      assistant_name: `BugCatcher AI ${marker}`,
      system_prompt: "Test runtime prompt",
      is_enabled: true,
    },
    {
      checklist_generator: {
        is_enabled: true,
        provider_config_id: createdProviderId,
        model_id: createdTextModelId,
        assistant_name: `Generator ${marker}`,
        system_prompt: "Generate checklist items from available product context.",
      },
      checklist_reviewer: {
        is_enabled: true,
        provider_config_id: createdProviderId,
        model_id: createdTextModelId,
        assistant_name: `Reviewer ${marker}`,
        system_prompt: "Review checklist items and return the same JSON schema.",
      },
    }
  );
  expect(textOnlySnapshot.runtime.assistant_name).toBe(`BugCatcher AI ${marker}`);
  expect(textOnlySnapshot.readiness.link.enabled).toBe(true);
  expect(textOnlySnapshot.readiness.screenshot.enabled).toBe(false);
  expect(textOnlySnapshot.readiness.screenshot.warning_message).toContain("vision-capable provider/model");
  expect(findPersona(textOnlySnapshot.personas, "checklist_generator").model_id).toBe(createdTextModelId);

  const visionSnapshot = await saveRuntimeConfig(
    {
      ...textOnlySnapshot.runtime,
      default_provider_config_id: createdProviderId,
      default_model_id: createdVisionModelId,
      assistant_name: `BugCatcher AI Vision ${marker}`,
      is_enabled: true,
    },
    {
      checklist_generator: {
        is_enabled: true,
        provider_config_id: createdProviderId,
        model_id: createdVisionModelId,
        assistant_name: `Generator Vision ${marker}`,
        system_prompt: "Draft practical QA checklist items.",
      },
      checklist_reviewer: {
        is_enabled: false,
        provider_config_id: createdProviderId,
        model_id: createdTextModelId,
        assistant_name: `Reviewer ${marker}`,
        system_prompt: "Review checklist items and keep the schema stable.",
      },
    }
  );
  expect(visionSnapshot.readiness.link.enabled).toBe(true);
  expect(visionSnapshot.readiness.screenshot.enabled).toBe(true);
  expect(visionSnapshot.readiness.screenshot.warning_message).toBe("");
  expect(findPersona(visionSnapshot.personas, "checklist_generator").model_id).toBe(createdVisionModelId);
  expect(findPersona(visionSnapshot.personas, "checklist_generator").supports_vision).toBe(true);
  expect(findPersona(visionSnapshot.personas, "checklist_reviewer").is_enabled).toBe(false);

  const patchedRuntime = await apiPatchJson<ApiEnvelope<AiRuntimeSnapshot & { saved: boolean }>>(
    api,
    `${cfg.apiBasePath}/admin/ai/runtime`,
    {
      ...visionSnapshot.runtime,
      personas: {
        checklist_generator: {
          is_enabled: true,
          provider_config_id: createdProviderId,
          model_id: createdVisionModelId,
          assistant_name: `Generator Vision ${marker}`,
          system_prompt: "Draft practical QA checklist items.",
        },
        checklist_reviewer: {
          is_enabled: false,
          provider_config_id: createdProviderId,
          model_id: createdTextModelId,
          assistant_name: `Reviewer ${marker}`,
          system_prompt: "Review checklist items and keep the schema stable.",
        },
      },
    },
    authHeaders(superAdmin)
  );
  expect(patchedRuntime.res.status()).toBe(200);
  expectApiSuccess(patchedRuntime.body);
  expect(patchedRuntime.body.data.readiness.screenshot.enabled).toBe(true);

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
});

test("ai chat bootstrap and page link preview statuses support saved basic auth reuse", async () => {
  const bootstrap = await apiGet<ApiEnvelope<AiBootstrap>>(
    api,
    `${cfg.apiBasePath}/ai-chat/bootstrap`,
    authHeaders(qaLead)
  );
  expect(bootstrap.res.status()).toBe(200);
  expectApiSuccess(bootstrap.body);
  expect(bootstrap.body.data.enabled).toBe(true);
  expect(bootstrap.body.data.source_modes.link.enabled).toBe(true);
  expect(bootstrap.body.data.source_modes.screenshot.enabled).toBe(true);
  expect(bootstrap.body.data.personas.some((row) => row.persona_key === "checklist_generator")).toBe(true);

  const thread = await createThread(`AI Chat Preview ${Date.now()}`);
  const readyUrl = previewFixtureUrl("ready.html");
  const loginUrl = previewFixtureUrl("login.html");
  const publicPortalUrl = previewFixtureUrl("public-portal-links.html");
  const basicAuthUrl = previewFixtureUrl("basic-auth.php");

  const readyPreview = await previewPageLink(thread.id, {
    page_url: readyUrl,
  });
  expect(readyPreview.page_link_preview.status).toBe("ready");
  expect(readyPreview.page_link_preview.page_title).toContain("BugCatcher AI Preview Fixture");
  expect(readyPreview.thread.draft_context.page_link_status).toBe("ready");

  const invalidPreview = await previewPageLink(thread.id, {
    page_url: "not-a-valid-link",
  });
  expect(invalidPreview.page_link_preview.status).toBe("invalid");
  expect(invalidPreview.page_link_preview.warning_message).toContain("valid http:// or https://");

  const unreachablePreview = await previewPageLink(thread.id, {
    page_url: "http://127.0.0.1:9/bugcatcher-ai-unreachable",
  });
  expect(unreachablePreview.page_link_preview.status).toBe("unreachable");

  const loginPreview = await previewPageLink(thread.id, {
    page_url: loginUrl,
  });
  expect(loginPreview.page_link_preview.status).toBe("unsupported_auth");
  expect(loginPreview.page_link_preview.warning_message).toContain("login screen");

  const publicPortalPreview = await previewPageLink(thread.id, {
    page_url: publicPortalUrl,
  });
  expect(publicPortalPreview.page_link_preview.status).toBe("ready");
  expect(publicPortalPreview.page_link_preview.page_title).toContain("Public Campus Homepage");

  const basicRequiredPreview = await previewPageLink(thread.id, {
    page_url: basicAuthUrl,
  });
  expect(basicRequiredPreview.page_link_preview.status).toBe("auth_required_basic");
  expect(basicRequiredPreview.page_link_preview.requires_credentials).toBe(true);
  expect(basicRequiredPreview.thread.draft_context.has_saved_link_credentials).toBe(false);

  const rejectedBasicPreview = await previewPageLink(thread.id, {
    page_url: basicAuthUrl,
    basic_auth_username: "fixture-user",
    basic_auth_password: "wrong-pass",
  });
  expect(rejectedBasicPreview.page_link_preview.status).toBe("auth_required_basic");
  expect(rejectedBasicPreview.page_link_preview.warning_message).toContain("rejected");
  expect(rejectedBasicPreview.thread.draft_context.has_saved_link_credentials).toBe(false);

  const acceptedBasicPreview = await previewPageLink(thread.id, {
    page_url: basicAuthUrl,
    basic_auth_username: "fixture-user",
    basic_auth_password: "fixture-pass",
  });
  expect(acceptedBasicPreview.page_link_preview.status).toBe("ready");
  expect(acceptedBasicPreview.page_link_preview.credentials_saved).toBe(true);
  expect(acceptedBasicPreview.thread.draft_context.has_saved_link_credentials).toBe(true);

  const reusedBasicPreview = await previewPageLink(thread.id, {
    page_url: basicAuthUrl,
  });
  expect(reusedBasicPreview.page_link_preview.status).toBe("ready");
  expect(reusedBasicPreview.page_link_preview.credentials_saved).toBe(true);
  expect(reusedBasicPreview.thread.draft_context.has_saved_link_credentials).toBe(true);
});

test("ai chat drafts honor link-only generation, screenshot requirements, and reviewer fallback", async () => {
  const marker = Date.now();
  const readyUrl = previewFixtureUrl("ready.html");

  const linkThread = await createThread(`AI Chat Link Draft ${marker}`);
  await updateDraftContext(linkThread.id, {
    project_id: cfg.projectId,
    source_mode: "link",
    target_mode: "new",
    batch_title: `AI Link Batch ${marker}`,
    module_name: "AI Chat",
    submodule_name: "Link",
    page_url: readyUrl,
  });
  await previewPageLink(linkThread.id, {
    page_url: readyUrl,
  });

  const linkDraft = await generateChecklistDraft(linkThread.id, "Draft link-based checklist items.");
  const linkAssistant = lastAssistantMessage(linkDraft.thread);
  expect(linkAssistant.content).toContain("I drafted 2 checklist items");
  expect(linkAssistant.generated_checklist_items?.length).toBeGreaterThan(0);
  for (const item of linkAssistant.generated_checklist_items ?? []) {
    expect(item.source_mode).toBe("link");
    expect(item.page_url).toBe(readyUrl);
  }

  const screenshotThread = await createThread(`AI Chat Screenshot Draft ${marker}`);
  await updateDraftContext(screenshotThread.id, {
    project_id: cfg.projectId,
    source_mode: "screenshot",
    target_mode: "new",
    batch_title: `AI Screenshot Batch ${marker}`,
    module_name: "AI Chat",
    submodule_name: "Screenshot",
    page_url: readyUrl,
  });
  await previewPageLink(screenshotThread.id, {
    page_url: readyUrl,
  });

  const screenshotFailure = await apiPostJson<ApiEnvelope<unknown>>(
    api,
    `${cfg.apiBasePath}/ai-chat/threads/${screenshotThread.id}/checklist-drafts`,
    { message: "Try without a screenshot first." },
    authHeaders(qaLead)
  );
  expect(screenshotFailure.res.status()).toBe(422);
  expect(screenshotFailure.body.ok).toBe(false);
  if (!screenshotFailure.body.ok) {
    expect(screenshotFailure.body.error.message).toContain("Add at least 1 screenshot");
  }

  const screenshotDraft = await generateChecklistDraftWithScreenshot(
    screenshotThread.id,
    "Now draft screenshot-based checklist items."
  );
  const screenshotAssistant = lastAssistantMessage(screenshotDraft.thread);
  expect(screenshotAssistant.generated_checklist_items?.length).toBeGreaterThan(0);
  for (const item of screenshotAssistant.generated_checklist_items ?? []) {
    expect(item.source_mode).toBe("screenshot");
    expect(item.page_url).toBe(readyUrl);
  }
});

test("existing batch page link override stays pending until an approved AI item is saved", async () => {
  const marker = Date.now();
  const oldUrl = `${previewFixtureUrl("ready.html")}?source=old`;
  const newUrl = `${previewFixtureUrl("ready.html")}?source=new`;
  const batchId = await createBatchFixture(marker, oldUrl);

  const batchBefore = await fetchBatch(batchId);
  expect(batchBefore.page_url).toBe(oldUrl);

  const thread = await createThread(`AI Chat Existing Batch ${marker}`);
  const updatedThread = await updateDraftContext(thread.id, {
    project_id: cfg.projectId,
    source_mode: "link",
    target_mode: "existing",
    existing_batch_id: batchId,
    page_url: newUrl,
  });
  expect(updatedThread.draft_context.page_url).toBe(newUrl);
  expect(updatedThread.draft_context.page_link_warning).toContain("will update the batch after an approved AI item is saved");

  const batchStillOriginal = await fetchBatch(batchId);
  expect(batchStillOriginal.page_url).toBe(oldUrl);

  const preview = await previewPageLink(thread.id, {
    page_url: newUrl,
  });
  expect(preview.page_link_preview.status).toBe("ready");

  const draft = await generateChecklistDraft(thread.id, "Draft checklist items for this existing batch.");
  const assistant = lastAssistantMessage(draft.thread);
  const firstGeneratedItem = assistant.generated_checklist_items?.[0];
  expect(firstGeneratedItem).toBeTruthy();
  expect(firstGeneratedItem?.page_url).toBe(newUrl);

  const approved = await apiPostJson<ApiEnvelope<{ generated_item: GeneratedItem }>>(
    api,
    `${cfg.apiBasePath}/ai-chat/generated-items/${firstGeneratedItem?.id}/approve`,
    {},
    authHeaders(qaLead)
  );
  expect(approved.res.status()).toBe(200);
  expectApiSuccess(approved.body);
  expect(approved.body.data.generated_item.review_status).toBe("approved");

  const batchAfter = await fetchBatch(batchId);
  expect(batchAfter.page_url).toBe(newUrl);
});
