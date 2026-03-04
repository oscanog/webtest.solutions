import crypto from "node:crypto";
import fs from "node:fs/promises";
import path from "node:path";

const PLUGIN_ID = "bugcatcher-openclaw";
const TOOL_NAME = "bugcatcher_create_bulk_checklist";
const DEFAULT_TIMEOUT_MS = 30000;
const DEFAULT_MAX_ATTACHMENTS = 10;
const DEFAULT_MAX_ATTACHMENT_BYTES = 20 * 1024 * 1024;
const DEFAULT_CLEANUP_MAX_AGE_MINUTES = 240;
const DEFAULT_RUNTIME_CONFIG_POLL_MS = 60000;
const DEFAULT_RELOAD_POLL_MS = 10000;
const DEFAULT_STATUS_POLL_MS = 60000;
const SUPPORTED_IMAGE_TYPES = new Set([
  "image/png",
  "image/jpeg",
  "image/jpg",
  "image/webp",
  "image/gif",
  "image/bmp",
  "image/tiff",
]);

const runtimeController = {
  backgroundStarted: false,
  configPollInFlight: false,
  statusPollInFlight: false,
  cache: null,
  lastAppliedConfigVersion: null,
  lastHeartbeatAt: null,
  lastReloadAt: null,
  lastConfigError: null,
  lastProviderError: null,
  lastDiscordError: null,
};

function pluginConfig(api) {
  return (
    api?.pluginConfig ??
    api?.config?.plugins?.entries?.[PLUGIN_ID]?.config ??
    {}
  );
}

function normalizedConfig(api) {
  const cfg = pluginConfig(api);
  const bugcatcherBaseUrl = String(cfg.bugcatcherBaseUrl ?? "").trim().replace(/\/+$/, "");
  const bugcatcherSharedSecret = String(cfg.bugcatcherSharedSecret ?? "").trim();
  const tempUploadDir = String(cfg.tempUploadDir ?? "").trim();

  if (!bugcatcherBaseUrl || !bugcatcherSharedSecret || !tempUploadDir) {
    throw new Error(
      `${PLUGIN_ID} is not configured. Set plugins.entries.${PLUGIN_ID}.config in openclaw.json.`,
    );
  }

  return {
    bugcatcherBaseUrl,
    bugcatcherSharedSecret,
    tempUploadDir,
    requestTimeoutMs: toInt(cfg.requestTimeoutMs, DEFAULT_TIMEOUT_MS),
    maxAttachments: toInt(cfg.maxAttachments, DEFAULT_MAX_ATTACHMENTS),
    maxAttachmentBytes: toInt(cfg.maxAttachmentBytes, DEFAULT_MAX_ATTACHMENT_BYTES),
    cleanupMaxAgeMinutes: toInt(cfg.cleanupMaxAgeMinutes, DEFAULT_CLEANUP_MAX_AGE_MINUTES),
    runtimeConfigPollMs: toInt(cfg.runtimeConfigPollMs, DEFAULT_RUNTIME_CONFIG_POLL_MS),
    reloadPollMs: toInt(cfg.reloadPollMs, DEFAULT_RELOAD_POLL_MS),
    statusPollMs: toInt(cfg.statusPollMs, DEFAULT_STATUS_POLL_MS),
  };
}

function toInt(value, fallback) {
  const parsed = Number.parseInt(String(value ?? ""), 10);
  return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback;
}

function toolResult(payload) {
  return {
    content: [
      {
        type: "text",
        text: JSON.stringify(payload, null, 2),
      },
    ],
  };
}

function assertString(value, fieldName) {
  const normalized = String(value ?? "").trim();
  if (!normalized) {
    throw new Error(`${fieldName} is required.`);
  }
  return normalized;
}

function assertPositiveInt(value, fieldName) {
  const normalized = Number.parseInt(String(value ?? ""), 10);
  if (!Number.isFinite(normalized) || normalized <= 0) {
    throw new Error(`${fieldName} must be a positive integer.`);
  }
  return normalized;
}

function safeFileName(value, fallback = "attachment") {
  const baseName = path.basename(String(value ?? fallback).trim() || fallback);
  return baseName.replace(/[^A-Za-z0-9._-]/g, "_");
}

function assertHttpUrl(value, fieldName) {
  const url = new URL(assertString(value, fieldName));
  if (url.protocol !== "http:" && url.protocol !== "https:") {
    throw new Error(`${fieldName} must use http or https.`);
  }
  return url;
}

function nowIso() {
  return new Date().toISOString();
}

function maskSecret(value) {
  const normalized = String(value ?? "").trim();
  if (!normalized) {
    return "Not set";
  }
  if (normalized.length <= 6) {
    return "*".repeat(normalized.length);
  }
  return `${normalized.slice(0, 3)}${"*".repeat(Math.max(4, normalized.length - 6))}${normalized.slice(-3)}`;
}

function sanitizeRuntimeSnapshot(snapshot) {
  if (!snapshot || typeof snapshot !== "object") {
    return snapshot;
  }

  const providers = Array.isArray(snapshot.providers)
    ? snapshot.providers.map((provider) => ({
        ...provider,
        api_key: maskSecret(provider?.api_key),
      }))
    : [];

  return {
    ...snapshot,
    runtime: {
      ...(snapshot.runtime ?? {}),
      discord_bot_token: maskSecret(snapshot?.runtime?.discord_bot_token),
    },
    providers,
  };
}

function currentDiscordState() {
  const runtime = runtimeController.cache?.runtime ?? {};
  if (!runtime.is_enabled) {
    return "disabled";
  }
  if (runtimeController.lastDiscordError) {
    return "error";
  }
  if (!String(runtime.discord_bot_token ?? "").trim()) {
    return "unconfigured";
  }
  return "configured";
}

function currentProviderError() {
  const runtime = runtimeController.cache?.runtime ?? {};
  if (!runtime.is_enabled) {
    return null;
  }
  if (runtimeController.lastProviderError) {
    return runtimeController.lastProviderError;
  }
  if (!Array.isArray(runtimeController.cache?.providers) || runtimeController.cache.providers.length === 0) {
    return "No enabled providers are configured in BugCatcher.";
  }
  if (!Array.isArray(runtimeController.cache?.models) || runtimeController.cache.models.length === 0) {
    return "No enabled models are configured in BugCatcher.";
  }
  return null;
}

function applyRuntimeSnapshot(snapshot) {
  runtimeController.cache = snapshot;
  runtimeController.lastAppliedConfigVersion = String(snapshot?.config_version ?? "").trim() || null;
  runtimeController.lastConfigError = null;
  runtimeController.lastProviderError = null;
  runtimeController.lastDiscordError = null;

  if (snapshot?.runtime?.is_enabled && !String(snapshot?.runtime?.discord_bot_token ?? "").trim()) {
    runtimeController.lastDiscordError = "Discord bot token is missing from the BugCatcher control plane.";
  }
  if (snapshot?.runtime?.is_enabled && (!Array.isArray(snapshot?.providers) || snapshot.providers.length === 0)) {
    runtimeController.lastProviderError = "No enabled providers are available in the BugCatcher control plane.";
  }
  if (snapshot?.runtime?.is_enabled && (!Array.isArray(snapshot?.models) || snapshot.models.length === 0)) {
    runtimeController.lastProviderError =
      runtimeController.lastProviderError ?? "No enabled models are available in the BugCatcher control plane.";
  }
}

async function ensureTempDir(dirPath) {
  await fs.mkdir(dirPath, { recursive: true });
}

async function cleanupExpiredFiles(dirPath, maxAgeMinutes) {
  const now = Date.now();
  const maxAgeMs = maxAgeMinutes * 60 * 1000;
  let entries = [];
  try {
    entries = await fs.readdir(dirPath, { withFileTypes: true });
  } catch (error) {
    if (error && error.code === "ENOENT") {
      return;
    }
    throw error;
  }

  await Promise.all(
    entries
      .filter((entry) => entry.isFile())
      .map(async (entry) => {
        const fullPath = path.join(dirPath, entry.name);
        const stat = await fs.stat(fullPath);
        if (now - stat.mtimeMs > maxAgeMs) {
          await fs.rm(fullPath, { force: true });
        }
      }),
  );
}

async function bugcatcherFetch(_api, cfg, method, endpoint, body) {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), cfg.requestTimeoutMs);
  try {
    const response = await fetch(`${cfg.bugcatcherBaseUrl}${endpoint}`, {
      method,
      headers: {
        Authorization: `Bearer ${cfg.bugcatcherSharedSecret}`,
        "Content-Type": "application/json",
      },
      body: body === undefined ? undefined : JSON.stringify(body),
      signal: controller.signal,
    });

    const rawText = await response.text();
    let parsed;
    try {
      parsed = rawText ? JSON.parse(rawText) : null;
    } catch {
      parsed = null;
    }

    if (!response.ok) {
      const detail =
        (parsed && typeof parsed.error === "string" && parsed.error) ||
        rawText ||
        `${response.status} ${response.statusText}`;
      throw new Error(`BugCatcher API ${endpoint} failed: ${detail}`);
    }

    return parsed;
  } finally {
    clearTimeout(timeout);
  }
}

async function stageAttachments(cfg, attachments) {
  if (!Array.isArray(attachments) || attachments.length === 0) {
    throw new Error("attachments must contain at least one item.");
  }
  if (attachments.length > cfg.maxAttachments) {
    throw new Error(`attachments exceeds maxAttachments (${cfg.maxAttachments}).`);
  }

  await ensureTempDir(cfg.tempUploadDir);
  await cleanupExpiredFiles(cfg.tempUploadDir, cfg.cleanupMaxAgeMinutes);

  const staged = [];
  for (const attachment of attachments) {
    if (!attachment || typeof attachment !== "object") {
      throw new Error("Each attachment must be an object.");
    }

    const url = assertHttpUrl(attachment.url, "attachments[].url");
    const originalName = safeFileName(attachment.originalName, "attachment");
    const response = await fetch(url, { redirect: "follow" });
    if (!response.ok) {
      throw new Error(`Failed to download attachment ${originalName}: ${response.status} ${response.statusText}`);
    }

    const contentType = String(
      attachment.contentType ??
        response.headers.get("content-type") ??
        "",
    )
      .toLowerCase()
      .split(";")[0]
      .trim();
    if (!SUPPORTED_IMAGE_TYPES.has(contentType)) {
      throw new Error(`Unsupported attachment type for ${originalName}: ${contentType || "unknown"}`);
    }

    const data = Buffer.from(await response.arrayBuffer());
    if (data.byteLength === 0) {
      throw new Error(`Attachment ${originalName} is empty.`);
    }
    if (data.byteLength > cfg.maxAttachmentBytes) {
      throw new Error(
        `Attachment ${originalName} exceeds maxAttachmentBytes (${cfg.maxAttachmentBytes}).`,
      );
    }

    const token = crypto.randomBytes(24).toString("hex");
    const destination = path.join(cfg.tempUploadDir, token);
    await fs.writeFile(destination, data, { mode: 0o640 });
    staged.push({
      temp_file_token: token,
      original_name: originalName,
      content_type: contentType,
      size_bytes: data.byteLength,
      path: destination,
    });
  }

  return staged;
}

async function cleanupTokens(cfg, tokens) {
  if (!Array.isArray(tokens) || tokens.length === 0) {
    return [];
  }

  const removed = [];
  for (const tokenValue of tokens) {
    const token = assertString(tokenValue, "tokens[]");
    const filePath = path.join(cfg.tempUploadDir, token);
    await fs.rm(filePath, { force: true });
    removed.push(token);
  }
  return removed;
}

function statusPayload(overrides = {}) {
  const payload = {
    heartbeat_at: nowIso(),
    config_version_applied: runtimeController.lastAppliedConfigVersion,
    gateway_state: "running",
    discord_state: currentDiscordState(),
    discord_application_id: null,
    last_provider_error: currentProviderError(),
    last_discord_error: runtimeController.lastDiscordError ?? runtimeController.lastConfigError,
    last_reload_at: runtimeController.lastReloadAt,
  };
  return { ...payload, ...overrides };
}

async function reportRuntimeStatus(api, cfg, overrides = {}) {
  if (runtimeController.statusPollInFlight) {
    return {
      ok: false,
      skipped: true,
    };
  }

  runtimeController.statusPollInFlight = true;
  try {
    const result = await bugcatcherFetch(api, cfg, "POST", "/api/openclaw/runtime_status.php", statusPayload(overrides));
    runtimeController.lastHeartbeatAt = nowIso();
    return result;
  } finally {
    runtimeController.statusPollInFlight = false;
  }
}

async function loadRuntimeConfig(api, cfg, { force = false, reason = "poll" } = {}) {
  if (runtimeController.configPollInFlight) {
    return runtimeController.cache;
  }

  runtimeController.configPollInFlight = true;
  try {
    const snapshot = await bugcatcherFetch(api, cfg, "GET", "/api/openclaw/runtime_config.php");
    const previousVersion = String(runtimeController.cache?.config_version ?? "");
    const nextVersion = String(snapshot?.config_version ?? "");
    const pendingReloadId = Number.parseInt(String(snapshot?.pending_reload_request?.id ?? ""), 10) || 0;
    const shouldApply =
      force ||
      !runtimeController.cache ||
      previousVersion !== nextVersion ||
      pendingReloadId > 0;

    if (shouldApply) {
      applyRuntimeSnapshot(snapshot);
      runtimeController.lastReloadAt = nowIso();
      const reloadStatus = pendingReloadId > 0
        ? {
            reload_request_id: pendingReloadId,
            reload_request_status: "completed",
            last_reload_at: runtimeController.lastReloadAt,
          }
        : {
            last_reload_at: runtimeController.lastReloadAt,
          };
      await reportRuntimeStatus(api, cfg, reloadStatus);
    } else if (reason === "startup" && !runtimeController.lastHeartbeatAt) {
      await reportRuntimeStatus(api, cfg);
    }

    return snapshot;
  } catch (error) {
    runtimeController.lastConfigError = error instanceof Error ? error.message : String(error);
    try {
      await reportRuntimeStatus(api, cfg, {
        last_discord_error: runtimeController.lastConfigError,
      });
    } catch {
      // Leave the last error in memory; the next poll will retry.
    }
    throw error;
  } finally {
    runtimeController.configPollInFlight = false;
  }
}

function startBackgroundWorkers(api) {
  if (runtimeController.backgroundStarted) {
    return;
  }

  runtimeController.backgroundStarted = true;
  const cfg = normalizedConfig(api);

  void loadRuntimeConfig(api, cfg, { force: true, reason: "startup" }).catch(() => {});

  setInterval(() => {
    void loadRuntimeConfig(api, cfg, { reason: "config_poll" }).catch(() => {});
  }, cfg.runtimeConfigPollMs);

  setInterval(() => {
    void loadRuntimeConfig(api, cfg, { reason: "reload_poll" }).catch(() => {});
  }, cfg.reloadPollMs);

  setInterval(() => {
    void reportRuntimeStatus(api, cfg).catch(() => {});
  }, cfg.statusPollMs);
}

async function executeAction(api, params) {
  const cfg = normalizedConfig(api);
  const action = assertString(params?.action, "action");

  switch (action) {
    case "health":
      return {
        action,
        health: await bugcatcherFetch(api, cfg, "GET", "/api/openclaw/health.php"),
      };
    case "load_runtime_config":
      return {
        action,
        runtime_config: sanitizeRuntimeSnapshot(
          await loadRuntimeConfig(api, cfg, {
            force: Boolean(params.force),
            reason: "tool_load_runtime_config",
          }),
        ),
      };
    case "reload_runtime_config":
      return {
        action,
        reload: await bugcatcherFetch(api, cfg, "POST", "/api/openclaw/runtime_reload.php", {
          reason: "plugin_manual_reload",
        }),
        runtime_config: sanitizeRuntimeSnapshot(
          await loadRuntimeConfig(api, cfg, {
            force: true,
            reason: "tool_reload_runtime_config",
          }),
        ),
      };
    case "report_runtime_status":
      return {
        action,
        status: await reportRuntimeStatus(api, cfg, params.status && typeof params.status === "object" ? params.status : {}),
      };
    case "confirm_link":
      return {
        action,
        result: await bugcatcherFetch(api, cfg, "POST", "/api/openclaw/link_confirm.php", {
          code: assertString(params.code, "code").toUpperCase(),
          discord_user_id: assertString(params.discordUserId, "discordUserId"),
          discord_username: String(params.discordUsername ?? "").trim(),
          discord_global_name: String(params.discordGlobalName ?? "").trim(),
        }),
      };
    case "load_context":
      return {
        action,
        context: await bugcatcherFetch(api, cfg, "POST", "/api/openclaw/link_context.php", {
          discord_user_id: assertString(params.discordUserId, "discordUserId"),
        }),
      };
    case "stage_attachments":
      return {
        action,
        attachments: await stageAttachments(cfg, params.attachments),
      };
    case "cleanup_attachments":
      return {
        action,
        removed_tokens: await cleanupTokens(cfg, params.tokens),
      };
    case "check_duplicates":
      return {
        action,
        duplicates: await bugcatcherFetch(
          api,
          cfg,
          "POST",
          "/api/openclaw/checklist_duplicates.php",
          {
            org_id: assertPositiveInt(params.orgId, "orgId"),
            project_id: assertPositiveInt(params.projectId, "projectId"),
            items: Array.isArray(params.items) ? params.items : [],
          },
        ),
      };
    case "submit_batch":
      if (!params.payload || typeof params.payload !== "object" || Array.isArray(params.payload)) {
        throw new Error("payload is required for submit_batch.");
      }
      return {
        action,
        submission: await bugcatcherFetch(
          api,
          cfg,
          "POST",
          "/api/openclaw/checklist_batches.php",
          params.payload,
        ),
      };
    default:
      throw new Error(
        `Unsupported action: ${action}. Expected health, load_runtime_config, reload_runtime_config, report_runtime_status, confirm_link, load_context, stage_attachments, cleanup_attachments, check_duplicates, or submit_batch.`,
      );
  }
}

export default function register(api) {
  startBackgroundWorkers(api);

  api.registerTool({
    name: TOOL_NAME,
    description:
      "Load BugCatcher OpenClaw control-plane config, link Discord users, stage inbound images, check duplicates, and submit checklist batches through BugCatcher's internal OpenClaw APIs.",
    parameters: {
      type: "object",
      additionalProperties: false,
      required: ["action"],
      properties: {
        action: {
          type: "string",
          enum: [
            "health",
            "load_runtime_config",
            "reload_runtime_config",
            "report_runtime_status",
            "confirm_link",
            "load_context",
            "stage_attachments",
            "cleanup_attachments",
            "check_duplicates",
            "submit_batch",
          ],
        },
        force: { type: "boolean" },
        code: { type: "string" },
        discordUserId: { type: "string" },
        discordUsername: { type: "string" },
        discordGlobalName: { type: "string" },
        orgId: { type: "integer", minimum: 1 },
        projectId: { type: "integer", minimum: 1 },
        items: {
          type: "array",
          items: {
            type: "object",
            additionalProperties: true,
          },
        },
        attachments: {
          type: "array",
          items: {
            type: "object",
            additionalProperties: false,
            required: ["url", "originalName"],
            properties: {
              url: { type: "string" },
              originalName: { type: "string" },
              contentType: { type: "string" },
            },
          },
        },
        tokens: {
          type: "array",
          items: { type: "string" },
        },
        payload: {
          type: "object",
          additionalProperties: true,
        },
        status: {
          type: "object",
          additionalProperties: true,
        },
      },
    },
    async execute(_id, params) {
      const payload = await executeAction(api, params);
      return toolResult(payload);
    },
  });
}
