import { expect, request, test, APIRequestContext } from "@playwright/test";
import { cfg } from "../src/config";
import fs from "node:fs";
import path from "node:path";
import { authHeaders, loginRole, RoleSession } from "./helpers/auth";
import {
  ApiEnvelope,
  apiDeleteJson,
  apiGet,
  apiPostJson,
  expectApiSuccess,
  parseJson,
} from "./helpers/client";

test.describe.configure({ mode: "serial" });

type Issue = {
  id: number;
  title: string;
  status: string;
  workflow_status: string;
  assign_status: string;
  assigned_dev_id: number;
  assigned_junior_id: number;
  assigned_qa_id: number;
  assigned_senior_qa_id: number;
  assigned_qa_lead_id: number;
};

type DashboardSummaryData = {
  summary: {
    open_issues: number;
    closed_issues: number;
  };
  recent_issues: Array<{
    id: number;
    title: string;
  }>;
  qa_lead_checklist: null | {
    org_totals: Array<{
      user_id: number | null;
      display_name: string;
      assigned_items: number;
      open_items: number;
      is_unassigned: boolean;
    }>;
    projects: Array<{
      project_id: number;
      project_name: string;
      assigned_items: number;
      open_items: number;
      testers: Array<{
        user_id: number | null;
        display_name: string;
        assigned_items: number;
        open_items: number;
        is_unassigned: boolean;
      }>;
    }>;
  };
};

type Sessions = {
  superAdmin: RoleSession;
  pm: RoleSession;
  seniorDev: RoleSession;
  juniorDev: RoleSession;
  qaTester: RoleSession;
  seniorQa: RoleSession;
  qaLead: RoleSession;
};

let api: APIRequestContext;
let sessions: Sessions;
const createdIssueIds: number[] = [];
const createdChecklistFixtures: Array<{ batchId: number; itemIds: number[] }> = [];
const issueFixturePath = path.resolve(__dirname, "fixtures", "sample.png");

async function postIssueAction(session: RoleSession, issueId: number, actionPath: string, payload: object) {
  const { res, body } = await apiPostJson<ApiEnvelope<{ issue: Issue }>>(
    api,
    `${cfg.apiBasePath}/issues/${issueId}/${actionPath}`,
    payload,
    authHeaders(session)
  );
  expect(res.status()).toBe(200);
  expectApiSuccess(body);
  return body.data.issue;
}

async function createIssue(title: string): Promise<Issue> {
  const { res, body } = await apiPostJson<ApiEnvelope<{ issue: Issue }>>(
    api,
    `${cfg.apiBasePath}/issues`,
    {
      org_id: cfg.orgId,
      project_id: cfg.projectId,
      title,
      description: "Created by API v1 e2e workflow test",
      labels: [cfg.labelId],
    },
    authHeaders(sessions.pm)
  );

  expect(res.status()).toBe(201);
  expectApiSuccess(body);
  createdIssueIds.push(body.data.issue.id);
  expect(body.data.issue.workflow_status).toBe("unassigned");
  expect(body.data.issue.status).toBe("open");
  expect(body.data.issue.assign_status).toBe("unassigned");
  return body.data.issue;
}

test("issues endpoint accepts multipart evidence uploads", async () => {
  const uploadRes = await api.post(`${cfg.apiBasePath}/issues`, {
    multipart: {
      org_id: String(cfg.orgId),
      project_id: String(cfg.projectId),
      title: `Multipart Issue ${Date.now()}`,
      description: "Created with issue evidence upload",
      "labels[]": String(cfg.labelId),
      "images[]": {
        name: "sample.png",
        mimeType: "image/png",
        buffer: fs.readFileSync(issueFixturePath),
      },
    },
    headers: authHeaders(sessions.pm),
  });

  const body = await parseJson<ApiEnvelope<{ issue: Issue & { attachments: Array<{ id: number; file_path: string; original_name: string }> } }>>(uploadRes);
  expect(uploadRes.status()).toBe(201);
  expectApiSuccess(body);
  createdIssueIds.push(body.data.issue.id);
  expect(body.data.issue.attachments.length).toBeGreaterThan(0);
  expect(body.data.issue.attachments[0]?.file_path).toBeTruthy();
});

async function moveToQaLead(issueId: number): Promise<void> {
  await postIssueAction(sessions.pm, issueId, "assign-dev", {
    org_id: cfg.orgId,
    dev_id: cfg.accounts.seniorDev.userId,
  });
  await postIssueAction(sessions.seniorDev, issueId, "assign-junior", {
    org_id: cfg.orgId,
    junior_id: cfg.accounts.juniorDev.userId,
  });
  await postIssueAction(sessions.juniorDev, issueId, "junior-done", {
    org_id: cfg.orgId,
  });
  await postIssueAction(sessions.seniorDev, issueId, "assign-qa", {
    org_id: cfg.orgId,
    qa_id: cfg.accounts.qaTester.userId,
  });
  await postIssueAction(sessions.qaTester, issueId, "report-senior-qa", {
    org_id: cfg.orgId,
    senior_qa_id: cfg.accounts.seniorQa.userId,
  });
  await postIssueAction(sessions.seniorQa, issueId, "report-qa-lead", {
    org_id: cfg.orgId,
    qa_lead_id: cfg.accounts.qaLead.userId,
  });
}

async function createChecklistFixture(marker: string): Promise<{ batchId: number; itemIds: number[] }> {
  const batchCreate = await apiPostJson<ApiEnvelope<{ batch: { id: number } }>>(
    api,
    `${cfg.apiBasePath}/checklist/batches`,
    {
      project_id: cfg.projectId,
      title: `QA Lead Dashboard ${marker}`,
      module_name: "Dashboard",
      submodule_name: "QA Lead",
      status: "open",
      assigned_qa_lead_id: cfg.accounts.qaLead.userId,
      notes: "Created by dashboard summary regression test",
    },
    authHeaders(sessions.pm)
  );
  expect(batchCreate.res.status()).toBe(201);
  expectApiSuccess(batchCreate.body);
  const batchId = batchCreate.body.data.batch.id;

  const createItem = async (payload: Record<string, unknown>) => {
    const itemCreate = await apiPostJson<ApiEnvelope<{ item: { id: number } }>>(
      api,
      `${cfg.apiBasePath}/checklist/items`,
      {
        batch_id: batchId,
        module_name: "Dashboard",
        submodule_name: "QA Lead",
        priority: "medium",
        required_role: "QA Tester",
        ...payload,
      },
      authHeaders(sessions.pm)
    );
    expect(itemCreate.res.status()).toBe(201);
    expectApiSuccess(itemCreate.body);
    return itemCreate.body.data.item.id;
  };

  const openAssignedItemId = await createItem({
    sequence_no: 1,
    title: `Assigned open ${marker}`,
    description: "Assigned QA tester open checklist item",
    assigned_to_user_id: cfg.accounts.qaTester.userId,
  });
  const passedAssignedItemId = await createItem({
    sequence_no: 2,
    title: `Assigned passed ${marker}`,
    description: "Assigned QA tester passed checklist item",
    assigned_to_user_id: cfg.accounts.qaTester.userId,
  });
  const unassignedOpenItemId = await createItem({
    sequence_no: 3,
    title: `Unassigned open ${marker}`,
    description: "Unassigned QA tester open checklist item",
    assigned_to_user_id: 0,
  });

  const statusUpdate = await apiPostJson<ApiEnvelope<{ item: { status: string } }>>(
    api,
    `${cfg.apiBasePath}/checklist/item_status`,
    {
      item_id: passedAssignedItemId,
      status: "passed",
    },
    authHeaders(sessions.qaTester)
  );
  expect(statusUpdate.res.status()).toBe(200);
  expectApiSuccess(statusUpdate.body);
  expect(statusUpdate.body.data.item.status).toBe("passed");

  const fixture = {
    batchId,
    itemIds: [openAssignedItemId, passedAssignedItemId, unassignedOpenItemId],
  };
  createdChecklistFixtures.push(fixture);
  return fixture;
}

async function deleteChecklistFixture(batchId: number, itemIds: number[]): Promise<void> {
  for (const itemId of itemIds) {
    const itemDelete = await api.fetch(`${cfg.apiBasePath}/checklist/item?id=${itemId}`, {
      method: "DELETE",
      headers: authHeaders(sessions.pm),
      data: {},
    });
    expect([200, 404]).toContain(itemDelete.status());
  }

  const batchDelete = await api.fetch(`${cfg.apiBasePath}/checklist/batch?id=${batchId}`, {
    method: "DELETE",
    headers: authHeaders(sessions.pm),
    data: {},
  });
  expect([200, 404]).toContain(batchDelete.status());
}

function findWorkloadRow(
  rows: NonNullable<DashboardSummaryData["qa_lead_checklist"]>["org_totals"] | NonNullable<DashboardSummaryData["qa_lead_checklist"]>["projects"][number]["testers"],
  matcher: { userId?: number; unassigned?: boolean }
) {
  return rows.find((row) =>
    matcher.unassigned ? row.is_unassigned : row.user_id === matcher.userId
  );
}

function findProjectSummary(data: DashboardSummaryData, projectId: number) {
  return data.qa_lead_checklist?.projects.find((project) => project.project_id === projectId);
}

test.beforeAll(async () => {
  api = await request.newContext({ baseURL: cfg.baseUrl });
  sessions = {
    superAdmin: await loginRole(api, "superAdmin"),
    pm: await loginRole(api, "pm"),
    seniorDev: await loginRole(api, "seniorDev"),
    juniorDev: await loginRole(api, "juniorDev"),
    qaTester: await loginRole(api, "qaTester"),
    seniorQa: await loginRole(api, "seniorQa"),
    qaLead: await loginRole(api, "qaLead"),
  };
});

test.afterAll(async () => {
  for (const fixture of createdChecklistFixtures.splice(0)) {
    await deleteChecklistFixture(fixture.batchId, fixture.itemIds);
  }
  for (const issueId of createdIssueIds) {
    await apiDeleteJson<ApiEnvelope<{ deleted?: boolean }>>(
      api,
      `${cfg.apiBasePath}/issues/${issueId}`,
      { org_id: cfg.orgId },
      authHeaders(sessions.superAdmin)
    );
  }
  await api.dispose();
});

test("issue workflow approve -> pm close", async () => {
  const listed = await apiGet<ApiEnvelope<{ issues: Issue[] }>>(
    api,
    `${cfg.apiBasePath}/issues?org_id=${cfg.orgId}&status=open`,
    authHeaders(sessions.pm)
  );
  expect(listed.res.status()).toBe(200);
  expectApiSuccess(listed.body);

  const issue = await createIssue(`API V1 workflow approve ${Date.now()}`);
  await moveToQaLead(issue.id);

  const approved = await postIssueAction(sessions.qaLead, issue.id, "qa-lead-approve", {
    org_id: cfg.orgId,
  });
  expect(approved.workflow_status).toBe("approved");
  expect(approved.status).toBe("open");
  expect(approved.assign_status).toBe("approved");

  const closed = await postIssueAction(sessions.pm, issue.id, "pm-close", {
    org_id: cfg.orgId,
  });
  expect(closed.workflow_status).toBe("closed");
  expect(closed.status).toBe("closed");
  expect(closed.assign_status).toBe("closed");

  const closedList = await apiGet<ApiEnvelope<{ issues: Issue[] }>>(
    api,
    `${cfg.apiBasePath}/issues?org_id=${cfg.orgId}&status=closed`,
    authHeaders(sessions.pm)
  );
  expect(closedList.res.status()).toBe(200);
  expectApiSuccess(closedList.body);
  expect(closedList.body.data.issues.some((row) => row.id === issue.id)).toBeTruthy();

  const allList = await apiGet<ApiEnvelope<{ issues: Issue[] }>>(
    api,
    `${cfg.apiBasePath}/issues?org_id=${cfg.orgId}&status=all`,
    authHeaders(sessions.pm)
  );
  expect(allList.res.status()).toBe(200);
  expectApiSuccess(allList.body);
  expect(allList.body.data.issues.some((row) => row.id === issue.id && row.workflow_status === "closed")).toBeTruthy();
});

test("issue reads are organization-wide while workflow actions stay role-scoped", async () => {
  const issue = await createIssue(`API V1 org visibility ${Date.now()}`);

  const juniorList = await apiGet<
    ApiEnvelope<{
      filters: { author: number | null };
      counts: { open: number; closed: number };
      issues: Issue[];
    }>
  >(
    api,
    `${cfg.apiBasePath}/issues?org_id=${cfg.orgId}&status=open`,
    authHeaders(sessions.juniorDev)
  );
  expect(juniorList.res.status()).toBe(200);
  expectApiSuccess(juniorList.body);
  expect(juniorList.body.data.filters.author).toBeNull();
  expect(juniorList.body.data.counts.open).toBeGreaterThan(0);
  expect(juniorList.body.data.issues.some((row) => row.id === issue.id)).toBe(true);

  const qaDetail = await apiGet<ApiEnvelope<{ issue: Issue }>>(
    api,
    `${cfg.apiBasePath}/issues/${issue.id}?org_id=${cfg.orgId}`,
    authHeaders(sessions.qaTester)
  );
  expect(qaDetail.res.status()).toBe(200);
  expectApiSuccess(qaDetail.body);
  expect(qaDetail.body.data.issue.id).toBe(issue.id);

  const juniorDashboard = await apiGet<ApiEnvelope<DashboardSummaryData>>(
    api,
    `${cfg.apiBasePath}/dashboard/summary?org_id=${cfg.orgId}`,
    authHeaders(sessions.juniorDev)
  );
  expect(juniorDashboard.res.status()).toBe(200);
  expectApiSuccess(juniorDashboard.body);
  expect(juniorDashboard.body.data.summary.open_issues).toBeGreaterThan(0);
  expect(juniorDashboard.body.data.recent_issues.some((row) => row.id === issue.id)).toBe(true);

  const blockedAction = await apiPostJson<ApiEnvelope<{ issue: Issue }>>(
    api,
    `${cfg.apiBasePath}/issues/${issue.id}/report-senior-qa`,
    {
      org_id: cfg.orgId,
      senior_qa_id: cfg.accounts.seniorQa.userId,
    },
    authHeaders(sessions.qaTester)
  );
  expect(blockedAction.res.status()).toBe(403);
  expect(blockedAction.body.ok).toBe(false);
  if (blockedAction.body.ok) {
    throw new Error("Expected QA action to stay restricted for non-assigned viewers.");
  }
  expect(blockedAction.body.error.message).toBe("You can only report issues assigned to you.");
});

test("qa lead dashboard includes org and project checklist workload summaries", async () => {
  test.setTimeout(45_000);
  const before = await apiGet<ApiEnvelope<DashboardSummaryData>>(
    api,
    `${cfg.apiBasePath}/dashboard/summary?org_id=${cfg.orgId}`,
    authHeaders(sessions.qaLead)
  );
  expect(before.res.status()).toBe(200);
  expectApiSuccess(before.body);
  expect(before.body.data.qa_lead_checklist).not.toBeNull();

  const beforeTesterRow = findWorkloadRow(before.body.data.qa_lead_checklist!.org_totals, {
    userId: cfg.accounts.qaTester.userId,
  });
  const beforeUnassignedRow = findWorkloadRow(before.body.data.qa_lead_checklist!.org_totals, {
    unassigned: true,
  });
  const beforeProject = findProjectSummary(before.body.data, cfg.projectId);
  const beforeProjectTester = beforeProject
    ? findWorkloadRow(beforeProject.testers, { userId: cfg.accounts.qaTester.userId })
    : undefined;
  const beforeProjectUnassigned = beforeProject
    ? findWorkloadRow(beforeProject.testers, { unassigned: true })
    : undefined;

  await createChecklistFixture(`${Date.now()}`);

  const after = await apiGet<ApiEnvelope<DashboardSummaryData>>(
    api,
    `${cfg.apiBasePath}/dashboard/summary?org_id=${cfg.orgId}`,
    authHeaders(sessions.qaLead)
  );
  expect(after.res.status()).toBe(200);
  expectApiSuccess(after.body);
  expect(after.body.data.qa_lead_checklist).not.toBeNull();

  const testerRow = findWorkloadRow(after.body.data.qa_lead_checklist!.org_totals, {
    userId: cfg.accounts.qaTester.userId,
  });
  expect(testerRow).toBeTruthy();
  expect(testerRow?.assigned_items).toBe((beforeTesterRow?.assigned_items ?? 0) + 2);
  expect(testerRow?.open_items).toBe((beforeTesterRow?.open_items ?? 0) + 1);

  const unassignedRow = findWorkloadRow(after.body.data.qa_lead_checklist!.org_totals, {
    unassigned: true,
  });
  expect(unassignedRow).toBeTruthy();
  expect(unassignedRow?.assigned_items).toBe((beforeUnassignedRow?.assigned_items ?? 0) + 1);
  expect(unassignedRow?.open_items).toBe((beforeUnassignedRow?.open_items ?? 0) + 1);

  const projectSummary = findProjectSummary(after.body.data, cfg.projectId);
  expect(projectSummary).toBeTruthy();
  expect(projectSummary?.assigned_items).toBe((beforeProject?.assigned_items ?? 0) + 3);
  expect(projectSummary?.open_items).toBe((beforeProject?.open_items ?? 0) + 2);

  const projectTesterRow = projectSummary
    ? findWorkloadRow(projectSummary.testers, { userId: cfg.accounts.qaTester.userId })
    : undefined;
  expect(projectTesterRow).toBeTruthy();
  expect(projectTesterRow?.assigned_items).toBe((beforeProjectTester?.assigned_items ?? 0) + 2);
  expect(projectTesterRow?.open_items).toBe((beforeProjectTester?.open_items ?? 0) + 1);

  const projectUnassignedRow = projectSummary
    ? findWorkloadRow(projectSummary.testers, { unassigned: true })
    : undefined;
  expect(projectUnassignedRow).toBeTruthy();
  expect(projectUnassignedRow?.assigned_items).toBe((beforeProjectUnassigned?.assigned_items ?? 0) + 1);
  expect(projectUnassignedRow?.open_items).toBe((beforeProjectUnassigned?.open_items ?? 0) + 1);

  const nonQaLeadDashboard = await apiGet<ApiEnvelope<DashboardSummaryData>>(
    api,
    `${cfg.apiBasePath}/dashboard/summary?org_id=${cfg.orgId}`,
    authHeaders(sessions.juniorDev)
  );
  expect(nonQaLeadDashboard.res.status()).toBe(200);
  expectApiSuccess(nonQaLeadDashboard.body);
  expect(nonQaLeadDashboard.body.data.qa_lead_checklist).toBeNull();
});

test("issue workflow reject -> reassign -> delete", async () => {
  const issue = await createIssue(`API V1 workflow reject ${Date.now()}`);
  await moveToQaLead(issue.id);

  const rejected = await postIssueAction(sessions.qaLead, issue.id, "qa-lead-reject", {
    org_id: cfg.orgId,
  });
  expect(rejected.workflow_status).toBe("rejected");
  expect(rejected.assign_status).toBe("rejected");
  expect(rejected.assigned_dev_id).toBe(0);

  const reassigned = await postIssueAction(sessions.pm, issue.id, "assign-dev", {
    org_id: cfg.orgId,
    dev_id: cfg.accounts.seniorDev.userId,
  });
  expect(reassigned.workflow_status).toBe("with_senior");
  expect(reassigned.assign_status).toBe("with_senior");

  const deleted = await apiDeleteJson<ApiEnvelope<{ deleted: boolean; issue_id: number }>>(
    api,
    `${cfg.apiBasePath}/issues/${issue.id}`,
    { org_id: cfg.orgId },
    authHeaders(sessions.superAdmin)
  );
  expect(deleted.res.status()).toBe(200);
  expectApiSuccess(deleted.body);
  expect(deleted.body.data.deleted).toBe(true);

  const index = createdIssueIds.indexOf(issue.id);
  if (index >= 0) {
    createdIssueIds.splice(index, 1);
  }
});
