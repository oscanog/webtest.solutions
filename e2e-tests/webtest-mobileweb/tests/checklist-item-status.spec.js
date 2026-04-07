const { expect, test } = require("../../api-v1/node_modules/@playwright/test");
const { cfg } = require("../src/config");
const { apiUrl, deleteIssueByApi, loginByApi, loginByUi } = require("./helpers");

function stripByteOrderMark(value) {
  return String(value || "").replace(/^\uFEFF/, "");
}

async function parseJsonResponse(response) {
  return JSON.parse(stripByteOrderMark(await response.text()));
}

async function createChecklistFixture(request, marker) {
  const accessToken = await loginByApi(request, "pm");

  const batchResponse = await request.post(apiUrl("/checklist/batches"), {
    headers: {
      Authorization: `Bearer ${accessToken}`,
    },
    data: {
      project_id: cfg.projectId,
      title: `Mobile Checklist Status ${marker}`,
      module_name: "Mobile",
      submodule_name: "Checklist",
      status: "open",
      assigned_qa_lead_id: 0,
      notes: "Created by mobile checklist status spec",
    },
  });
  expect(batchResponse.status()).toBe(201);
  const batchBody = await parseJsonResponse(batchResponse);
  expect(batchBody.ok).toBe(true);

  const batchId = batchBody.data.batch.id;
  const itemResponse = await request.post(apiUrl("/checklist/items"), {
    headers: {
      Authorization: `Bearer ${accessToken}`,
    },
    data: {
      batch_id: batchId,
      sequence_no: 1,
      title: `Assigned QA item ${marker}`,
      module_name: "Mobile",
      submodule_name: "Checklist",
      description: "Created by mobile checklist status spec",
      required_role: "QA Tester",
      priority: "medium",
      assigned_to_user_id: cfg.accounts.qaTester.userId,
    },
  });
  expect(itemResponse.status()).toBe(201);
  const itemBody = await parseJsonResponse(itemResponse);
  expect(itemBody.ok).toBe(true);

  return {
    accessToken,
    batchId,
    itemId: itemBody.data.item.id,
  };
}

async function deleteChecklistFixture(request, accessToken, batchId, itemId) {
  if (itemId > 0) {
    const itemResponse = await request.fetch(apiUrl(`/checklist/item?id=${itemId}`), {
      method: "DELETE",
      headers: {
        Authorization: `Bearer ${accessToken}`,
      },
      data: {},
    });
    expect([200, 404]).toContain(itemResponse.status());
  }

  if (batchId > 0) {
    const batchResponse = await request.fetch(apiUrl(`/checklist/batch?id=${batchId}`), {
      method: "DELETE",
      headers: {
        Authorization: `Bearer ${accessToken}`,
      },
      data: {},
    });
    expect([200, 404]).toContain(batchResponse.status());
  }
}

test("assigned qa tester updates checklist status from item detail without manager-only edit access", async ({ page, request }) => {
  const marker = Date.now();
  const fixture = await createChecklistFixture(request, marker);

  try {
    await loginByUi(page, "qaTester");
    await page.goto(`/app/checklist/items/${fixture.itemId}`);

    await expect(page.getByRole("heading", { name: "Workflow" })).toBeVisible();
    await expect(page.getByText("Only checklist managers can perform this action.")).toHaveCount(0);
    await expect(page.getByRole("heading", { name: "Edit Item" })).toHaveCount(0);
    await expect(page.getByRole("button", { name: "Save changes" })).toHaveCount(0);
    await expect(page.getByRole("button", { name: "Save assignment" })).toHaveCount(0);
    await expect(page.getByRole("button", { name: "Delete item" })).toHaveCount(0);

    await page.locator("select.select-inline").first().selectOption("passed");
    await page.getByRole("button", { name: "Update Status" }).click();

    await expect(page.getByText("Status updated.")).toBeVisible();
    await expect(page.getByText("Only checklist managers can perform this action.")).toHaveCount(0);
    await expect(page.locator(".detail-pairs").first()).toContainText("Status");
    await expect(page.locator(".detail-pairs").first()).toContainText("passed");
  } finally {
    await deleteChecklistFixture(request, fixture.accessToken, fixture.batchId, fixture.itemId);
  }
});

test("linked issues created from checklist failures open for org members without access errors", async ({ page, request }) => {
  test.setTimeout(40_000);
  const marker = Date.now();
  const fixture = await createChecklistFixture(request, marker);
  let linkedIssueId = 0;

  try {
    await loginByUi(page, "qaTester");
    await page.goto(`/app/checklist/items/${fixture.itemId}`);

    await page.locator("select.select-inline").first().selectOption("failed");
    await page.getByRole("button", { name: "Update Status" }).click();

    await expect(page.getByText("Status updated.")).toBeVisible();
    const linkedIssueLink = page.getByRole("link", { name: "Open linked issue" });
    await expect(linkedIssueLink).toBeVisible();

    const href = await linkedIssueLink.getAttribute("href");
    expect(href).toMatch(/^\/app\/reports\/\d+$/);
    linkedIssueId = Number((href || "").split("/").pop());

    await linkedIssueLink.click();

    await expect(page).toHaveURL(new RegExp(`/app/reports/${linkedIssueId}$`));
    await expect(page.getByText("You do not have access to this issue.")).toHaveCount(0);
    await expect(page.getByText(`Assigned QA item ${marker}`)).toBeVisible();
    await expect(page.getByText("No workflow action is available for your role and the current issue state.")).toBeVisible();
  } finally {
    if (linkedIssueId > 0) {
      await deleteIssueByApi(request, linkedIssueId);
    }
    await deleteChecklistFixture(request, fixture.accessToken, fixture.batchId, fixture.itemId);
  }
});
