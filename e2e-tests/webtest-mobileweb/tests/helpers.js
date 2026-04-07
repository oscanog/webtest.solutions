const { expect } = require("../../api-v1/node_modules/@playwright/test");
const { cfg } = require("../src/config");

const allDrawerLabels = [
  "Super Admin",
  "AI Admin",
  "Checklist",
  "Manage Users",
  "Settings",
  "Logout",
];

function stripByteOrderMark(value) {
  return String(value || "").replace(/^\uFEFF/, "").replace(/^Ã¯Â»Â¿/, "");
}

async function parseJsonResponse(response) {
  return JSON.parse(stripByteOrderMark(await response.text()));
}

function apiUrl(pathname) {
  return `${cfg.apiBaseUrl}${cfg.apiBasePath}${pathname.startsWith("/") ? pathname : `/${pathname}`}`;
}

async function loginByUi(page, roleKey) {
  const account = cfg.accounts[roleKey];
  await page.goto("/login");
  await page.locator('input[name="email"]').fill(account.email);
  await page.locator('input[name="password"]').fill(account.password);
  await page.getByRole("button", { name: "Login" }).click();
  await page.waitForURL(/\/app(\/dashboard|\/organizations)?$/);
  await expect(page.locator(".top-bar h1")).toBeVisible();
}

async function openDrawer(page) {
  await page.getByRole("button", { name: "Open navigation drawer" }).click();
  await expect(page.locator(".side-drawer.is-open")).toBeVisible();
}

async function closeDrawer(page) {
  if (await page.locator(".side-drawer.is-open").count()) {
    await page.getByRole("button", { name: "Close navigation drawer" }).click();
  }
}

async function loginByApi(request, roleKey) {
  const account = cfg.accounts[roleKey];
  const response = await request.post(apiUrl("/auth/login"), {
    data: {
      email: account.email,
      password: account.password,
      active_org_id: cfg.orgId,
    },
  });
  expect(response.status()).toBe(200);
  const body = await parseJsonResponse(response);
  expect(body.ok).toBe(true);
  return body.data.tokens.access_token;
}

async function createIssueByApi(request, roleKey, title) {
  const accessToken = await loginByApi(request, roleKey);
  const response = await request.post(apiUrl("/issues"), {
    headers: {
      Authorization: `Bearer ${accessToken}`,
    },
    data: {
      org_id: cfg.orgId,
      project_id: cfg.projectId,
      title,
      description: "Created by mobile web Playwright suite",
      labels: [cfg.labelId],
    },
  });
  expect(response.status()).toBe(201);
  const body = await parseJsonResponse(response);
  expect(body.ok).toBe(true);
  return {
    issueId: body.data.issue.id,
    accessToken,
  };
}

async function fetchNotificationsByApi(request, roleKey, state = "all") {
  const accessToken = await loginByApi(request, roleKey);
  const response = await request.get(apiUrl(`/notifications?state=${state}&limit=50`), {
    headers: {
      Authorization: `Bearer ${accessToken}`,
    },
  });
  expect(response.status()).toBe(200);
  const body = await parseJsonResponse(response);
  expect(body.ok).toBe(true);
  return {
    accessToken,
    notifications: body.data.items,
    unreadCount: body.data.unread_count,
  };
}

async function deleteIssueByApi(request, issueId) {
  const accessToken = await loginByApi(request, "superAdmin");
  const response = await request.fetch(apiUrl(`/issues/${issueId}`), {
    method: "DELETE",
    headers: {
      Authorization: `Bearer ${accessToken}`,
    },
    data: {
      org_id: cfg.orgId,
    },
  });
  expect([200, 404]).toContain(response.status());
}

module.exports = {
  allDrawerLabels,
  apiUrl,
  closeDrawer,
  createIssueByApi,
  deleteIssueByApi,
  fetchNotificationsByApi,
  loginByApi,
  loginByUi,
  openDrawer,
};
