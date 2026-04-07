const { test, expect } = require("../../api-v1/node_modules/@playwright/test");
const { cfg } = require("../src/config");
const { allDrawerLabels, loginByUi, openDrawer } = require("./helpers");

const roles = [
  {
    key: "superAdmin",
    allowedDrawerLabels: ["Super Admin", "AI Admin", "Checklist", "Manage Users", "Settings", "Logout"],
    blockedRoutes: [],
    allowedRoutes: ["/app/ai-admin", "/app/checklist"],
  },
  {
    key: "admin",
    allowedDrawerLabels: ["Settings", "Logout"],
    blockedRoutes: ["/app/super-admin", "/app/ai-admin", "/app/openclaw", "/app/manage-users", "/app/checklist"],
    allowedRoutes: [],
  },
  {
    key: "pm",
    allowedDrawerLabels: ["Checklist", "Settings", "Logout"],
    blockedRoutes: ["/app/super-admin", "/app/ai-admin", "/app/openclaw", "/app/manage-users"],
    allowedRoutes: ["/app/checklist"],
  },
  {
    key: "seniorDev",
    allowedDrawerLabels: ["Settings", "Logout"],
    blockedRoutes: ["/app/super-admin", "/app/ai-admin", "/app/openclaw", "/app/manage-users", "/app/checklist"],
    allowedRoutes: [],
  },
  {
    key: "juniorDev",
    allowedDrawerLabels: ["Settings", "Logout"],
    blockedRoutes: ["/app/super-admin", "/app/ai-admin", "/app/openclaw", "/app/manage-users", "/app/checklist"],
    allowedRoutes: [],
  },
  {
    key: "qaTester",
    allowedDrawerLabels: ["Settings", "Logout"],
    blockedRoutes: ["/app/super-admin", "/app/ai-admin", "/app/openclaw", "/app/manage-users", "/app/checklist"],
    allowedRoutes: [],
  },
  {
    key: "seniorQa",
    allowedDrawerLabels: ["Settings", "Logout"],
    blockedRoutes: ["/app/super-admin", "/app/ai-admin", "/app/openclaw", "/app/manage-users", "/app/checklist"],
    allowedRoutes: [],
  },
  {
    key: "qaLead",
    allowedDrawerLabels: ["Checklist", "Settings", "Logout"],
    blockedRoutes: ["/app/super-admin", "/app/ai-admin", "/app/openclaw", "/app/manage-users"],
    allowedRoutes: ["/app/checklist"],
  },
];

for (const role of roles) {
  test(`${role.key} sees the exact allowed utility drawer items and reflected role labels`, async ({ page }) => {
    const account = cfg.accounts[role.key];
    await loginByUi(page, role.key);
    await page.goto("/app/profile");
    const sessionPairs = page.locator(".detail-pair");
    await expect(sessionPairs.filter({ hasText: `System Role${account.systemRole}` }).first()).toBeVisible();
    await expect(sessionPairs.filter({ hasText: `Org Role${account.orgRole}` }).first()).toBeVisible();

    await openDrawer(page);
    const drawerSection = page.locator(".drawer-section");

    for (const label of role.allowedDrawerLabels) {
      await expect(drawerSection.getByText(label, { exact: true })).toBeVisible();
    }

    const blockedLabels = allDrawerLabels.filter((label) => !role.allowedDrawerLabels.includes(label));
    for (const label of blockedLabels) {
      await expect(drawerSection.getByText(label, { exact: true })).toHaveCount(0);
    }
  });

  test(`${role.key} respects dedicated route gates`, async ({ page }) => {
    await loginByUi(page, role.key);

    if (role.blockedRoutes.length === 0) {
      await page.goto("/app/super-admin");
      await expect(page).toHaveURL(/\/app\/super-admin$/);
      await page.goto("/app/openclaw");
      await expect(page).toHaveURL(/\/app\/ai-admin$/);
      await page.goto("/app/manage-users");
      await expect(page).toHaveURL(/\/app\/manage-users$/);
      return;
    }

    for (const route of role.blockedRoutes) {
      await page.goto(route);
      await expect(page).toHaveURL(/\/app\/dashboard$/);
    }

    for (const route of role.allowedRoutes) {
      await page.goto(route);
      await expect(page).toHaveURL(new RegExp(`${route.replace("/", "\\/")}$`));
    }
  });
}
