import { expect, request, test, APIRequestContext } from "@playwright/test";
import { cfg } from "../src/config";
import { authHeaders, loginRole, RoleSession } from "./helpers/auth";
import {
  ApiEnvelope,
  apiDeleteJson,
  apiGet,
  apiPostJson,
  expectApiSuccess,
} from "./helpers/client";

test.describe.configure({ mode: "serial" });

type Issue = {
  id: number;
  title: string;
  status: string;
  assign_status: string;
  assigned_dev_id: number;
  assigned_junior_id: number;
  assigned_qa_id: number;
  assigned_senior_qa_id: number;
  assigned_qa_lead_id: number;
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
      title,
      description: "Created by API v1 e2e workflow test",
      labels: [cfg.labelId],
    },
    authHeaders(sessions.pm)
  );

  expect(res.status()).toBe(201);
  expectApiSuccess(body);
  createdIssueIds.push(body.data.issue.id);
  return body.data.issue;
}

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
  expect(approved.assign_status).toBe("approved");

  const closed = await postIssueAction(sessions.pm, issue.id, "pm-close", {
    org_id: cfg.orgId,
  });
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
});

test("issue workflow reject -> reassign -> delete", async () => {
  const issue = await createIssue(`API V1 workflow reject ${Date.now()}`);
  await moveToQaLead(issue.id);

  const rejected = await postIssueAction(sessions.qaLead, issue.id, "qa-lead-reject", {
    org_id: cfg.orgId,
  });
  expect(rejected.assign_status).toBe("rejected");
  expect(rejected.assigned_dev_id).toBe(0);

  const reassigned = await postIssueAction(sessions.pm, issue.id, "assign-dev", {
    org_id: cfg.orgId,
    dev_id: cfg.accounts.seniorDev.userId,
  });
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
