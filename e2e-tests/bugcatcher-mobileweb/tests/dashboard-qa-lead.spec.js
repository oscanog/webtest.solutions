const { expect, test } = require("../../api-v1/node_modules/@playwright/test");
const { cfg } = require("../src/config");
const { apiUrl, loginByApi, loginByUi } = require("./helpers");

function stripByteOrderMark(value) {
  return String(value || "").replace(/^\uFEFF/, "");
}

async function parseJsonResponse(response) {
  return JSON.parse(stripByteOrderMark(await response.text()));
}

async function createDashboardChecklistFixture(request, marker) {
  const pmAccessToken = await loginByApi(request, "pm");
  const qaTesterAccessToken = await loginByApi(request, "qaTester");

  const batchResponse = await request.post(apiUrl("/checklist/batches"), {
    headers: {
      Authorization: `Bearer ${pmAccessToken}`,
    },
    data: {
      project_id: cfg.projectId,
      title: `QA Lead Dashboard ${marker}`,
      module_name: "Dashboard",
      submodule_name: "QA Lead",
      status: "open",
      assigned_qa_lead_id: cfg.accounts.qaLead.userId,
      notes: "Created by mobile QA lead dashboard spec",
    },
  });
  expect(batchResponse.status()).toBe(201);
  const batchBody = await parseJsonResponse(batchResponse);
  expect(batchBody.ok).toBe(true);

  const batchId = batchBody.data.batch.id;

  async function createItem(payload) {
    const itemResponse = await request.post(apiUrl("/checklist/items"), {
      headers: {
        Authorization: `Bearer ${pmAccessToken}`,
      },
      data: {
        batch_id: batchId,
        module_name: "Dashboard",
        submodule_name: "QA Lead",
        required_role: "QA Tester",
        priority: "medium",
        ...payload,
      },
    });
    expect(itemResponse.status()).toBe(201);
    const itemBody = await parseJsonResponse(itemResponse);
    expect(itemBody.ok).toBe(true);
    return itemBody.data.item.id;
  }

  const assignedOpenItemId = await createItem({
    sequence_no: 1,
    title: `Assigned open ${marker}`,
    description: "Assigned QA tester open checklist item",
    assigned_to_user_id: cfg.accounts.qaTester.userId,
  });
  const assignedPassedItemId = await createItem({
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

  const statusResponse = await request.post(apiUrl("/checklist/item_status"), {
    headers: {
      Authorization: `Bearer ${qaTesterAccessToken}`,
    },
    data: {
      item_id: assignedPassedItemId,
      status: "passed",
    },
  });
  expect(statusResponse.status()).toBe(200);
  const statusBody = await parseJsonResponse(statusResponse);
  expect(statusBody.ok).toBe(true);

  return {
    accessToken: pmAccessToken,
    batchId,
    itemIds: [assignedOpenItemId, assignedPassedItemId, unassignedOpenItemId],
  };
}

async function deleteChecklistFixture(request, accessToken, batchId, itemIds) {
  for (const itemId of itemIds) {
    const itemResponse = await request.fetch(apiUrl(`/checklist/item?id=${itemId}`), {
      method: "DELETE",
      headers: {
        Authorization: `Bearer ${accessToken}`,
      },
      data: {},
    });
    expect([200, 404]).toContain(itemResponse.status());
  }

  const batchResponse = await request.fetch(apiUrl(`/checklist/batch?id=${batchId}`), {
    method: "DELETE",
    headers: {
      Authorization: `Bearer ${accessToken}`,
    },
    data: {},
  });
  expect([200, 404]).toContain(batchResponse.status());
}

async function fetchDashboardSummary(request, roleKey) {
  const accessToken = await loginByApi(request, roleKey);
  const response = await request.get(apiUrl(`/dashboard/summary?org_id=${cfg.orgId}`), {
    headers: {
      Authorization: `Bearer ${accessToken}`,
    },
  });
  expect(response.status()).toBe(200);
  const body = await parseJsonResponse(response);
  expect(body.ok).toBe(true);
  return body.data;
}

test("qa lead dashboard shows tester checklist workload from the summary API", async ({ page, request }) => {
  test.setTimeout(45_000);
  const marker = Date.now();
  const fixture = await createDashboardChecklistFixture(request, marker);

  try {
    const dashboard = await fetchDashboardSummary(request, "qaLead");
    expect(dashboard.qa_lead_checklist).toBeTruthy();

    const testerRow = dashboard.qa_lead_checklist.org_totals.find(
      (row) => row.user_id === cfg.accounts.qaTester.userId
    );
    const unassignedRow = dashboard.qa_lead_checklist.org_totals.find((row) => row.is_unassigned);
    const projectRow = dashboard.qa_lead_checklist.projects.find((project) => project.project_id === cfg.projectId);

    expect(testerRow).toBeTruthy();
    expect(unassignedRow).toBeTruthy();
    expect(projectRow).toBeTruthy();

    await loginByUi(page, "qaLead");
    await page.goto("/app/dashboard");

    await expect(page.getByRole("heading", { name: "QA Tester Workload" })).toBeVisible();
    await expect(page.getByText(testerRow.display_name, { exact: true }).first()).toBeVisible();
    await expect(page.getByText(new RegExp(`Assigned ${testerRow.assigned_items}.*Open ${testerRow.open_items}`)).first()).toBeVisible();
    await expect(page.getByText(unassignedRow.display_name, { exact: true }).first()).toBeVisible();
    await expect(page.getByText(new RegExp(`Assigned ${unassignedRow.assigned_items}.*Open ${unassignedRow.open_items}`)).first()).toBeVisible();

    await expect(page.getByRole("heading", { name: "By Project" })).toBeVisible();
    await expect(page.getByText(projectRow.project_name, { exact: true }).first()).toBeVisible();
    await expect(page.getByText(`Assigned ${projectRow.assigned_items}`, { exact: true }).first()).toBeVisible();
    await expect(page.getByText(`Open ${projectRow.open_items}`, { exact: true }).first()).toBeVisible();
  } finally {
    await deleteChecklistFixture(request, fixture.accessToken, fixture.batchId, fixture.itemIds);
  }
});

test("non-qa leads do not see the QA tester workload dashboard section", async ({ page }) => {
  await loginByUi(page, "qaTester");
  await page.goto("/app/dashboard");

  await expect(page.getByRole("heading", { name: "QA Tester Workload" })).toHaveCount(0);
  await expect(page.getByRole("heading", { name: "By Project" })).toHaveCount(0);
});
