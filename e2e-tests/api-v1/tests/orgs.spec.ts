import { expect, request, test, APIRequestContext } from "@playwright/test";
import { cfg } from "../src/config";
import { authHeaders, loginRole, RoleSession } from "./helpers/auth";
import {
  ApiEnvelope,
  apiDeleteJson,
  apiGet,
  apiPatchJson,
  apiPostJson,
  expectApiSuccess,
} from "./helpers/client";

test.describe.configure({ mode: "serial" });

type OrgRecord = {
  id: number;
  name: string;
  my_role: string;
};

let api: APIRequestContext;
let superAdmin: RoleSession;
let admin: RoleSession;

let tempOrgId = 0;

const tempOrgName = `E2E V1 Org ${Date.now()}`;

test.beforeAll(async () => {
  api = await request.newContext({ baseURL: cfg.baseUrl });
  superAdmin = await loginRole(api, "superAdmin");
  admin = await loginRole(api, "admin");
});

test.afterAll(async () => {
  await api.dispose();
});

test("organization lifecycle endpoints", async () => {
  const orgsInitial = await apiGet<
    ApiEnvelope<{ active_org_id: number; organizations: OrgRecord[]; joinable_organizations: OrgRecord[] }>
  >(api, `${cfg.apiBasePath}/orgs`, authHeaders(superAdmin));
  expect(orgsInitial.res.status()).toBe(200);
  expectApiSuccess(orgsInitial.body);

  const created = await apiPostJson<ApiEnvelope<{ created: boolean; org_id: number; active_org_id: number }>>(
    api,
    `${cfg.apiBasePath}/orgs`,
    { name: tempOrgName },
    authHeaders(superAdmin)
  );
  expect(created.res.status()).toBe(201);
  expectApiSuccess(created.body);
  tempOrgId = created.body.data.org_id;
  expect(tempOrgId).toBeGreaterThan(0);

  const joined = await apiPostJson<ApiEnvelope<{ joined: boolean; org_id: number }>>(
    api,
    `${cfg.apiBasePath}/orgs/${tempOrgId}/join`,
    {},
    authHeaders(admin)
  );
  expect(joined.res.status()).toBe(200);
  expectApiSuccess(joined.body);

  const changedRole = await apiPatchJson<ApiEnvelope<{ updated: boolean; role: string }>>(
    api,
    `${cfg.apiBasePath}/orgs/${tempOrgId}/members/${admin.userId}/role`,
    { role: "QA Tester" },
    authHeaders(superAdmin)
  );
  expect(changedRole.res.status()).toBe(200);
  expectApiSuccess(changedRole.body);
  expect(changedRole.body.data.role).toBe("QA Tester");

  const kicked = await apiDeleteJson<ApiEnvelope<{ kicked: boolean; user_id: number }>>(
    api,
    `${cfg.apiBasePath}/orgs/${tempOrgId}/members/${admin.userId}`,
    undefined,
    authHeaders(superAdmin)
  );
  expect(kicked.res.status()).toBe(200);
  expectApiSuccess(kicked.body);

  const rejoined = await apiPostJson<ApiEnvelope<{ joined: boolean; org_id: number }>>(
    api,
    `${cfg.apiBasePath}/orgs/${tempOrgId}/join`,
    {},
    authHeaders(admin)
  );
  expect(rejoined.res.status()).toBe(200);
  expectApiSuccess(rejoined.body);

  const transferred = await apiPostJson<ApiEnvelope<{ transferred: boolean; new_owner_id: number }>>(
    api,
    `${cfg.apiBasePath}/orgs/${tempOrgId}/transfer-owner`,
    { new_owner_id: admin.userId },
    authHeaders(superAdmin)
  );
  expect(transferred.res.status()).toBe(200);
  expectApiSuccess(transferred.body);
  expect(transferred.body.data.new_owner_id).toBe(admin.userId);

  const superAdminLeave = await apiPostJson<ApiEnvelope<{ left: boolean; org_id: number }>>(
    api,
    `${cfg.apiBasePath}/orgs/${tempOrgId}/leave`,
    {},
    authHeaders(superAdmin)
  );
  expect(superAdminLeave.res.status()).toBe(200);
  expectApiSuccess(superAdminLeave.body);

  const deleted = await apiDeleteJson<ApiEnvelope<{ deleted: boolean; org_id: number }>>(
    api,
    `${cfg.apiBasePath}/orgs/${tempOrgId}`,
    { confirm: "DELETE" },
    authHeaders(admin)
  );
  expect(deleted.res.status()).toBe(200);
  expectApiSuccess(deleted.body);
  expect(deleted.body.data.deleted).toBe(true);

  tempOrgId = 0;
});

test.afterEach(async () => {
  if (tempOrgId <= 0) {
    return;
  }

  const cleanup = await apiDeleteJson<ApiEnvelope<{ deleted?: boolean; error?: unknown }>>(
    api,
    `${cfg.apiBasePath}/orgs/${tempOrgId}`,
    { confirm: "DELETE" },
    authHeaders(superAdmin)
  );
  if (cleanup.res.status() === 200) {
    tempOrgId = 0;
  }
});
