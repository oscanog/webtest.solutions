import { expect, request, test, APIRequestContext } from "@playwright/test";
import { cfg } from "../src/config";
import { authHeaders, loginRole, RoleSession } from "./helpers/auth";
import {
  ApiEnvelope,
  apiGet,
  apiPatchJson,
  apiPostJson,
  expectApiSuccess,
} from "./helpers/client";

test.describe.configure({ mode: "serial" });

type Project = {
  id: number;
  name: string;
  code?: string | null;
  status: string;
};

let api: APIRequestContext;
let pm: RoleSession;
let projectId = 0;

test.beforeAll(async () => {
  api = await request.newContext({ baseURL: cfg.baseUrl });
  pm = await loginRole(api, "pm");
});

test.afterAll(async () => {
  await api.dispose();
});

test("projects CRUD + archive/activate", async () => {
  const list = await apiGet<ApiEnvelope<{ org: { org_id: number }; projects: Project[] }>>(
    api,
    `${cfg.apiBasePath}/projects?org_id=${cfg.orgId}`,
    authHeaders(pm)
  );
  expect(list.res.status()).toBe(200);
  expectApiSuccess(list.body);

  const marker = Date.now();
  const created = await apiPostJson<ApiEnvelope<{ project: Project }>>(
    api,
    `${cfg.apiBasePath}/projects`,
    {
      org_id: cfg.orgId,
      name: `API V1 E2E Project ${marker}`,
      code: `E2E-${marker}`,
      description: "Created by API v1 e2e suite",
      status: "active",
    },
    authHeaders(pm)
  );
  expect(created.res.status()).toBe(201);
  expectApiSuccess(created.body);
  projectId = created.body.data.project.id;
  expect(projectId).toBeGreaterThan(0);

  const detail = await apiGet<ApiEnvelope<{ project: Project; batches: unknown[] }>>(
    api,
    `${cfg.apiBasePath}/projects/${projectId}?org_id=${cfg.orgId}`,
    authHeaders(pm)
  );
  expect(detail.res.status()).toBe(200);
  expectApiSuccess(detail.body);
  expect(detail.body.data.project.id).toBe(projectId);

  const patched = await apiPatchJson<ApiEnvelope<{ project: Project }>>(
    api,
    `${cfg.apiBasePath}/projects/${projectId}`,
    {
      org_id: cfg.orgId,
      name: `API V1 E2E Project ${marker} Updated`,
      description: "Updated via patch",
      status: "active",
    },
    authHeaders(pm)
  );
  expect(patched.res.status()).toBe(200);
  expectApiSuccess(patched.body);
  expect(patched.body.data.project.name).toContain("Updated");

  const archived = await apiPostJson<ApiEnvelope<{ project_id: number; status: string }>>(
    api,
    `${cfg.apiBasePath}/projects/${projectId}/archive`,
    { org_id: cfg.orgId },
    authHeaders(pm)
  );
  expect(archived.res.status()).toBe(200);
  expectApiSuccess(archived.body);
  expect(archived.body.data.status).toBe("archived");

  const activated = await apiPostJson<ApiEnvelope<{ project_id: number; status: string }>>(
    api,
    `${cfg.apiBasePath}/projects/${projectId}/activate`,
    { org_id: cfg.orgId },
    authHeaders(pm)
  );
  expect(activated.res.status()).toBe(200);
  expectApiSuccess(activated.body);
  expect(activated.body.data.status).toBe("active");
});
