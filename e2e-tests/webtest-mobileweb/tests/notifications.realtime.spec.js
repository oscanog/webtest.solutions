const { test, expect } = require("../../api-v1/node_modules/@playwright/test");
const {
  createIssueByApi,
  deleteIssueByApi,
  loginByUi,
} = require("./helpers");

test.describe.configure({ mode: "serial" });

const createdIssueIds = [];

test.afterEach(async ({ request }) => {
  while (createdIssueIds.length) {
    const issueId = createdIssueIds.pop();
    if (issueId) {
      await deleteIssueByApi(request, issueId);
    }
  }
});

test("PM receives a new notification in realtime without reloading", async ({ page, request }) => {
  await loginByUi(page, "pm");
  await page.goto("/app/notifications");
  await expect(page.getByText(/live notifications connected/i)).toBeVisible();

  const issueTitle = `Realtime notif ${Date.now()}`;
  const created = await createIssueByApi(request, "superAdmin", issueTitle);
  createdIssueIds.push(created.issueId);

  const item = page.locator(".list-row--button").filter({ hasText: issueTitle }).first();
  await expect(item).toBeVisible({ timeout: 15000 });
  await expect(item.getByText("Unread", { exact: true })).toBeVisible();
});

test("Notification read state syncs across two open PM sessions", async ({ browser, request }) => {
  const issueTitle = `Realtime read sync ${Date.now()}`;
  const created = await createIssueByApi(request, "superAdmin", issueTitle);
  createdIssueIds.push(created.issueId);

  const context = await browser.newContext();
  const pageOne = await context.newPage();

  await loginByUi(pageOne, "pm");
  await pageOne.goto("/app/notifications");
  await expect(pageOne.getByText(/live notifications connected/i)).toBeVisible();

  const pageTwo = await context.newPage();
  await pageTwo.goto("/app/notifications");
  await expect(pageTwo.getByText(/live notifications connected/i)).toBeVisible();

  const unreadOnFirst = pageOne.locator(".list-row--button").filter({ hasText: issueTitle }).first();
  const unreadOnSecond = pageTwo.locator(".list-row--button").filter({ hasText: issueTitle }).first();
  await expect(unreadOnFirst).toBeVisible({ timeout: 15000 });
  await expect(unreadOnSecond).toBeVisible({ timeout: 15000 });

  await unreadOnFirst.click();
  await expect(pageOne).toHaveURL(new RegExp(`/app/reports/${created.issueId}$`));

  await pageTwo.goto("/app/notifications");
  const syncedItem = pageTwo.locator(".list-row--button").filter({ hasText: issueTitle }).first();
  await expect(syncedItem.getByText("Read", { exact: true })).toBeVisible({ timeout: 15000 });

  await context.close();
});
