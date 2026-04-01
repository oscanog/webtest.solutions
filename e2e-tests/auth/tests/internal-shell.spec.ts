import { expect, test, type Page } from "@playwright/test";
import { cfg } from "../src/config";

const localOnlyMessage = "This suite relies on the seeded local legacy accounts.";
const sharedPassword = "BugCatcherProd!20260323";

const localAccounts = {
  superAdmin: {
    email: "m.viner001@gmail.com",
    password: sharedPassword,
  },
  admin: {
    email: "mackrafanan9247@gmail.com",
    password: sharedPassword,
  },
  user: {
    email: "emmanuelmagnosulit@gmail.com",
    password: sharedPassword,
  },
} as const;

const baseNavItems = ["Dashboard", "Issues", "Organization", "Projects", "Checklist", "Logout"];

const routeMatrix = [
  {
    path: "zen/dashboard.php?page=dashboard",
    heading: "Dashboard",
    activeNav: "Dashboard",
    expectsIssuesTheme: true,
  },
  {
    path: "zen/dashboard.php?page=issues&view=kanban&status=all",
    heading: "Issues",
    activeNav: "Issues",
    expectsIssuesTheme: true,
  },
  {
    path: "zen/organization.php",
    heading: "Organization",
    activeNav: "Organization",
    expectsIssuesTheme: false,
  },
  {
    path: "melvin/project_list.php",
    heading: "Projects",
    activeNav: "Projects",
    expectsIssuesTheme: false,
  },
  {
    path: "melvin/checklist_list.php",
    heading: "Checklist",
    activeNav: "Checklist",
    expectsIssuesTheme: false,
  },
  {
    path: "super-admin/ai.php",
    heading: "AI Setup",
    activeNav: "AI Admin",
    expectsIssuesTheme: false,
  },
] as const;

async function loginAs(page: Page, email: string, password: string): Promise<void> {
  await page.goto("rainier/login.php");
  await page.getByPlaceholder("Email Address").fill(email);
  await page.getByPlaceholder("Password").fill(password);
  await page.getByRole("button", { name: "Login" }).click();
  await expect(page).not.toHaveURL(/\/rainier\/login\.php/);
  await expect(page.locator(".bc-sidebar")).toBeVisible();
}

async function stylesheetHrefs(page: Page): Promise<string[]> {
  return page.locator('link[rel="stylesheet"]').evaluateAll((nodes) =>
    nodes
      .map((node) => node.getAttribute("href") || "")
      .filter((href) => href !== "")
  );
}

async function firstIssueDetailHref(page: Page): Promise<string | null> {
  const kanbanHref = await page.locator(".issue-kanban-card").first().getAttribute("href");
  if (kanbanHref) {
    return kanbanHref;
  }

  return page.locator("[data-issue-link]").first().getAttribute("data-issue-link");
}

test.describe("legacy internal shell", () => {
  test.skip(cfg.isProduction, localOnlyMessage);

  test("role-based sidebar items stay centralized", async ({ page }) => {
    await loginAs(page, localAccounts.superAdmin.email, localAccounts.superAdmin.password);
    const superAdminSidebar = page.locator(".bc-sidebar");

    for (const label of [...baseNavItems, "AI Admin"]) {
      await expect(superAdminSidebar.getByRole("link", { name: label, exact: true })).toBeVisible();
    }

    await page.goto("rainier/logout.php");

    await loginAs(page, localAccounts.admin.email, localAccounts.admin.password);
    const adminSidebar = page.locator(".bc-sidebar");

    for (const label of baseNavItems) {
      await expect(adminSidebar.getByRole("link", { name: label, exact: true })).toBeVisible();
    }
    await expect(adminSidebar.getByRole("link", { name: "AI Admin", exact: true })).toHaveCount(0);

    await page.goto("rainier/logout.php");

    await loginAs(page, localAccounts.user.email, localAccounts.user.password);
    const userSidebar = page.locator(".bc-sidebar");

    for (const label of baseNavItems) {
      await expect(userSidebar.getByRole("link", { name: label, exact: true })).toBeVisible();
    }
    await expect(userSidebar.getByRole("link", { name: "AI Admin", exact: true })).toHaveCount(0);
  });

  test("route matrix uses the shared shell and expected stylesheets", async ({ page }) => {
    await loginAs(page, localAccounts.superAdmin.email, localAccounts.superAdmin.password);

    for (const route of routeMatrix) {
      await page.goto(route.path);
      await expect(page.getByRole("heading", { name: route.heading, exact: true })).toBeVisible();
      await expect(page.locator(".bc-sidebar")).toBeVisible();
      await expect(page.locator(".bc-nav a.active")).toHaveText(route.activeNav);

      const hrefs = await stylesheetHrefs(page);
      expect(hrefs.some((href) => href.includes("app/legacy_theme.css?v=2"))).toBeTruthy();
      expect(hrefs.some((href) => href.includes("app/legacy_issues.css?v=2"))).toBe(route.expectsIssuesTheme);
    }
  });

  test("issue child pages keep the shared issues shell", async ({ page }) => {
    await loginAs(page, localAccounts.superAdmin.email, localAccounts.superAdmin.password);

    await page.goto("zen/create_issue.php");
    await expect(page.getByRole("heading", { name: "New Issue", exact: true })).toBeVisible();
    await expect(page.locator(".bc-nav a.active")).toHaveText("Issues");

    let hrefs = await stylesheetHrefs(page);
    expect(hrefs.some((href) => href.includes("app/legacy_theme.css?v=2"))).toBeTruthy();
    expect(hrefs.some((href) => href.includes("app/legacy_issues.css?v=2"))).toBeTruthy();

    await page.goto("zen/dashboard.php?page=issues&view=kanban&status=all");
    const detailHref = await firstIssueDetailHref(page);
    expect(detailHref).toBeTruthy();

    await page.goto(String(detailHref));
    await expect(page.locator(".bc-sidebar")).toBeVisible();
    await expect(page.locator(".bc-nav a.active")).toHaveText("Issues");
    await expect(page.getByRole("link", { name: "Back to Issues", exact: true })).toBeVisible();

    hrefs = await stylesheetHrefs(page);
    expect(hrefs.some((href) => href.includes("app/legacy_theme.css?v=2"))).toBeTruthy();
    expect(hrefs.some((href) => href.includes("app/legacy_issues.css?v=2"))).toBeTruthy();
  });

  test("mobile drawer opens and closes from the shared sidebar controls", async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await loginAs(page, localAccounts.superAdmin.email, localAccounts.superAdmin.password);
    await page.goto("zen/dashboard.php?page=dashboard");

    const toggle = page.locator(".bc-mobile-toggle");
    const sidebar = page.locator(".bc-sidebar");
    const backdrop = page.locator(".bc-mobile-backdrop");

    await expect(toggle).toBeVisible();
    await expect(sidebar).not.toHaveClass(/is-open/);

    await toggle.click();
    await expect(sidebar).toHaveClass(/is-open/);
    await expect(backdrop).toHaveClass(/is-visible/);

    await page.keyboard.press("Escape");
    await expect(sidebar).not.toHaveClass(/is-open/);
    await expect(backdrop).not.toHaveClass(/is-visible/);
  });
});
