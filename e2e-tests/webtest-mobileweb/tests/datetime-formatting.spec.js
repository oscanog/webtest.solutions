const { test, expect } = require("../../api-v1/node_modules/@playwright/test");
const { cfg } = require("../src/config");
const { loginByUi } = require("./helpers");

const APP_TIMEZONE = "Asia/Singapore";

function escapeRegex(value) {
  return String(value).replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}

function formatSingaporeParts(date) {
  const parts = new Intl.DateTimeFormat("en-CA", {
    timeZone: APP_TIMEZONE,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
    hour12: false,
  }).formatToParts(date);

  const read = (type) => parts.find((part) => part.type === type)?.value || "00";
  return {
    year: read("year"),
    month: read("month"),
    day: read("day"),
    hour: read("hour"),
    minute: read("minute"),
    second: read("second"),
  };
}

function toSingaporeSql(date) {
  const parts = formatSingaporeParts(date);
  return `${parts.year}-${parts.month}-${parts.day} ${parts.hour}:${parts.minute}:${parts.second}`;
}

function toSingaporeIso(date) {
  return `${toSingaporeSql(date).replace(" ", "T")}+08:00`;
}

test("notifications render singapore timestamps correctly in a UTC browser context", async ({ browser }) => {
  const context = await browser.newContext({ timezoneId: "UTC" });
  const page = await context.newPage();
  const createdAt = new Date(Date.now() - 30 * 60 * 1000);
  const notificationsPattern = new RegExp(`${escapeRegex(cfg.apiBasePath)}/notifications\\?state=all&limit=50$`);

  await page.route(notificationsPattern, async (route) => {
    await route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({
        ok: true,
        data: {
          items: [
            {
              id: 99001,
              type: "system",
              event_key: "timezone_probe",
              title: "Timezone probe",
              body: "Notification timestamps should respect Asia/Singapore.",
              severity: "default",
              link_path: "/app/notifications",
              read_at: null,
              read_at_iso: null,
              created_at: toSingaporeSql(createdAt),
              created_at_iso: toSingaporeIso(createdAt),
              org_id: cfg.orgId,
              project_id: null,
              issue_id: null,
              checklist_batch_id: null,
              checklist_item_id: null,
              actor: null,
              meta: null,
            },
          ],
          unread_count: 1,
          total_count: 1,
        },
      }),
    });
  });

  try {
    await loginByUi(page, "pm");
    await page.goto("/app/notifications");

    await expect(page.getByText("Timezone probe", { exact: true })).toBeVisible();
    await expect(page.getByText(/(29|30|31) minutes ago/)).toBeVisible();
  } finally {
    await context.close();
  }
});

test("project detail formats singapore wall-clock time correctly in a UTC browser context", async ({ browser }) => {
  const context = await browser.newContext({ timezoneId: "UTC" });
  const page = await context.newPage();
  const createdAt = new Date("2026-03-24T23:40:00Z");
  const expectedCreatedLabel = new Intl.DateTimeFormat("en-US", {
    timeZone: APP_TIMEZONE,
    month: "short",
    day: "numeric",
    hour: "numeric",
    minute: "2-digit",
  }).format(createdAt);
  const projectPattern = new RegExp(`${escapeRegex(cfg.apiBasePath)}/projects/${cfg.projectId}\\?org_id=${cfg.orgId}$`);

  await page.route(projectPattern, async (route) => {
    await route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({
        ok: true,
        data: {
          project: {
            id: cfg.projectId,
            org_id: cfg.orgId,
            org_name: "WebTest QA",
            name: "Timezone Project",
            code: "TZ-01",
            description: "Project detail timestamp regression check",
            status: "active",
            created_by: cfg.accounts.pm.userId,
            updated_by: null,
            created_at: toSingaporeSql(createdAt),
            created_at_iso: toSingaporeIso(createdAt),
            updated_at: null,
            updated_at_iso: null,
          },
          batches: [],
        },
      }),
    });
  });

  try {
    await loginByUi(page, "pm");
    await page.goto(`/app/projects/${cfg.projectId}`);

    await expect(page.getByText("Timezone Project", { exact: true })).toBeVisible();
    await expect(page.getByText(expectedCreatedLabel, { exact: true })).toBeVisible();
  } finally {
    await context.close();
  }
});
