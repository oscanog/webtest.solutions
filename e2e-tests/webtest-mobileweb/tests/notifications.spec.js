const { test, expect } = require("../../api-v1/node_modules/@playwright/test");
const {
  createIssueByApi,
  deleteIssueByApi,
  fetchNotificationsByApi,
  loginByUi,
} = require("./helpers");

test.describe.configure({ mode: "serial" });

let createdIssueId = 0;

test.afterEach(async ({ request }) => {
  if (createdIssueId > 0) {
    await deleteIssueByApi(request, createdIssueId);
    createdIssueId = 0;
  }
});

test("PM can open a notification, navigate to the issue, and the item is marked as read", async ({ page, request }) => {
  const issueTitle = `Mobile notif ${Date.now()}`;
  const created = await createIssueByApi(request, "superAdmin", issueTitle);
  createdIssueId = created.issueId;

  await loginByUi(page, "pm");
  await page.goto("/app/notifications");

  const item = page.locator(".list-row--button").filter({ hasText: issueTitle }).first();
  await expect(item).toBeVisible();
  await expect(item.getByText("Unread", { exact: true })).toBeVisible();

  await item.click();

  await expect(page).toHaveURL(new RegExp(`/app/reports/${created.issueId}$`));
  await expect(page.getByText(issueTitle, { exact: true })).toBeVisible();

  await page.goto("/app/notifications");
  const { notifications } = await fetchNotificationsByApi(request, "pm", "all");
  const matching = notifications.find((entry) => entry.body.includes(issueTitle) || entry.link_path === `/app/reports/${created.issueId}`);
  expect(matching).toBeTruthy();
  expect(matching.read_at).not.toBeNull();
});
