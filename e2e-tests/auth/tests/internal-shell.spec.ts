import { expect, test, type APIRequestContext, type Page } from "@playwright/test";
import { cfg } from "../src/config";

const localOnlyMessage = "This suite relies on the seeded local legacy accounts.";
const sharedPassword = "WebTestProd!20260323";

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
const legacyOrgId = 1;
const legacyProjectId = 1;
const legacyLabelId = 1;

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
  {
    path: "app/notifications.php",
    heading: "Notifications",
    activeNav: "",
    expectsIssuesTheme: false,
    expectsNotificationsTheme: true,
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

async function injectSidebarOverflow(page: Page): Promise<void> {
  await page.locator(".bc-nav").evaluate((nav) => {
    for (let index = 0; index < 24; index += 1) {
      const link = document.createElement("a");
      link.href = "#overflow-" + index;
      link.textContent = "Overflow Nav " + index;
      link.dataset.testOverflow = "1";
      nav.appendChild(link);
    }
  });
}

async function expectSharedSessionHeader(page: Page, heading: string): Promise<void> {
  await expect(page.getByRole("heading", { name: heading, exact: true })).toBeVisible();
  const session = page.locator(".bc-session");
  await expect(session).toBeVisible();
  await expect(session.locator(".bc-session-copy")).toContainText(/Welcome,\s+.+\s+\(.+\)/);
  await expect(page.getByRole("button", { name: "Open notifications" })).toBeVisible();
  await expect(page.getByRole("button", { name: "Open user profile menu" })).toBeVisible();
  await expect(session.getByRole("link", { name: "Logout", exact: true })).toHaveCount(0);
}

async function openUserMenu(page: Page): Promise<void> {
  await page.getByRole("button", { name: "Open user profile menu" }).click();
  await expect(page.locator(".bc-user-menu-panel")).toBeVisible();
}

async function openNotificationsMenu(page: Page): Promise<void> {
  await page.getByRole("button", { name: "Open notifications" }).click();
  await expect(page.locator(".bc-notifications-panel")).toBeVisible();
}

async function apiLogin(request: APIRequestContext, email: string, password: string): Promise<string> {
  const response = await request.post("api/v1/auth/login", {
    data: { email, password, active_org_id: legacyOrgId },
  });
  expect(response.status()).toBe(200);
  const payload = await response.json();
  expect(payload?.ok).toBeTruthy();
  return String(payload.data.tokens.access_token);
}

async function apiCreateIssue(request: APIRequestContext, accessToken: string, title: string): Promise<number> {
  const response = await request.post("api/v1/issues", {
    headers: {
      Authorization: `Bearer ${accessToken}`,
      Accept: "application/json",
    },
    data: {
      org_id: legacyOrgId,
      project_id: legacyProjectId,
      title,
      description: "Created by auth shell notification coverage.",
      labels: [legacyLabelId],
    },
  });

  expect(response.status()).toBe(201);
  const payload = await response.json();
  expect(payload?.ok).toBeTruthy();
  return Number(payload.data.issue.id);
}

async function apiDeleteIssue(request: APIRequestContext, accessToken: string, issueId: number): Promise<void> {
  if (issueId <= 0) {
    return;
  }

  await request.delete(`api/v1/issues/${issueId}`, {
    headers: {
      Authorization: `Bearer ${accessToken}`,
      Accept: "application/json",
    },
    data: {
      org_id: legacyOrgId,
    },
  });
}

async function apiFetchProject(
  request: APIRequestContext,
  accessToken: string,
  projectId: number
): Promise<{ id: number; name: string; code: string; description: string; status: string }> {
  const response = await request.get(`api/v1/projects/${projectId}?org_id=${legacyOrgId}`, {
    headers: {
      Authorization: `Bearer ${accessToken}`,
      Accept: "application/json",
    },
  });

  expect(response.status()).toBe(200);
  const payload = await response.json();
  expect(payload?.ok).toBeTruthy();
  return payload.data.project;
}

async function apiPatchProject(
  request: APIRequestContext,
  accessToken: string,
  projectId: number,
  nextProject: { name: string; code: string; description: string; status: string }
): Promise<void> {
  const response = await request.fetch(`api/v1/projects/${projectId}`, {
    method: "PATCH",
    headers: {
      Authorization: `Bearer ${accessToken}`,
      Accept: "application/json",
    },
    data: {
      org_id: legacyOrgId,
      name: nextProject.name,
      code: nextProject.code,
      description: nextProject.description,
      status: nextProject.status,
    },
  });

  expect(response.status()).toBe(200);
  const payload = await response.json();
  expect(payload?.ok).toBeTruthy();
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
      await expectSharedSessionHeader(page, route.heading);
      await expect(page.locator(".bc-sidebar")).toBeVisible();
      if (route.activeNav) {
        await expect(page.locator(".bc-nav a.active")).toHaveText(route.activeNav);
      } else {
        await expect(page.locator(".bc-nav a.active")).toHaveCount(0);
      }

      const hrefs = await stylesheetHrefs(page);
      expect(hrefs.some((href) => href.includes("app/legacy_theme.css?v=7"))).toBeTruthy();
      expect(hrefs.some((href) => href.includes("app/legacy_issues.css?v=2"))).toBe(route.expectsIssuesTheme);
      expect(hrefs.some((href) => href.includes("app/legacy_notifications.css?v=1"))).toBe(
        "expectsNotificationsTheme" in route ? Boolean(route.expectsNotificationsTheme) : false
      );
    }
  });

  test("issue child pages keep the shared issues shell", async ({ page }) => {
    await loginAs(page, localAccounts.superAdmin.email, localAccounts.superAdmin.password);

    await page.goto("zen/create_issue.php");
    await expectSharedSessionHeader(page, "New Issue");
    await expect(page.locator(".bc-nav a.active")).toHaveText("Issues");
    await expect(page.locator(".bc-subheader-actions")).toContainText("Back");
    await expect(page.locator(".bc-session")).not.toContainText("Back");

    let hrefs = await stylesheetHrefs(page);
    expect(hrefs.some((href) => href.includes("app/legacy_theme.css?v=7"))).toBeTruthy();
    expect(hrefs.some((href) => href.includes("app/legacy_issues.css?v=2"))).toBeTruthy();

    await page.goto("zen/dashboard.php?page=issues&view=kanban&status=all");
    const detailHref = await firstIssueDetailHref(page);
    expect(detailHref).toBeTruthy();
    const issueIdMatch = String(detailHref).match(/id=(\d+)/);
    const issueHeading = issueIdMatch ? `Issue #${issueIdMatch[1]}` : "Issue #16";

    await page.goto(String(detailHref));
    await expect(page.locator(".bc-sidebar")).toBeVisible();
    await expectSharedSessionHeader(page, issueHeading);
    await expect(page.locator(".bc-nav a.active")).toHaveText("Issues");
    await expect(page.locator(".bc-subheader-actions")).toContainText("Back to Issues");
    await expect(page.locator(".bc-session")).not.toContainText("Back to Issues");

    hrefs = await stylesheetHrefs(page);
    expect(hrefs.some((href) => href.includes("app/legacy_theme.css?v=7"))).toBeTruthy();
    expect(hrefs.some((href) => href.includes("app/legacy_issues.css?v=2"))).toBeTruthy();
  });

  test("avatar menu opens profile page and closes on outside click or escape", async ({ page }) => {
    await loginAs(page, localAccounts.superAdmin.email, localAccounts.superAdmin.password);
    await page.goto("zen/dashboard.php?page=dashboard");

    await openUserMenu(page);
    await expect(page.getByRole("menuitem", { name: "Profile", exact: true })).toBeVisible();
    await expect(page.getByRole("menuitem", { name: "Logout", exact: true })).toBeVisible();

    await page.mouse.click(40, 40);
    await expect(page.locator(".bc-user-menu-panel")).toBeHidden();

    await openUserMenu(page);
    await page.keyboard.press("Escape");
    await expect(page.locator(".bc-user-menu-panel")).toBeHidden();

    await openUserMenu(page);
    await page.getByRole("menuitem", { name: "Profile", exact: true }).click();
    await expect(page).toHaveURL(/\/app\/profile\.php$/);
    await expectSharedSessionHeader(page, "Profile");
    await expect(page.locator(".bc-sidebar").getByRole("link", { name: "Profile", exact: true })).toHaveCount(0);
    await expect(page.locator("[data-profile-root]")).toBeVisible();
  });

  test("notifications trigger sits left of the avatar and the dropdown opens or closes cleanly", async ({ page }) => {
    await loginAs(page, localAccounts.superAdmin.email, localAccounts.superAdmin.password);
    await page.goto("zen/dashboard.php?page=dashboard");
    await expectSharedSessionHeader(page, "Dashboard");

    const triggerBox = await page.getByRole("button", { name: "Open notifications" }).boundingBox();
    const avatarBox = await page.getByRole("button", { name: "Open user profile menu" }).boundingBox();
    expect(triggerBox).toBeTruthy();
    expect(avatarBox).toBeTruthy();
    expect((triggerBox?.x ?? 0) + (triggerBox?.width ?? 0)).toBeLessThan((avatarBox?.x ?? 0));

    await openNotificationsMenu(page);
    const dropdownItems = await page.locator(".bc-notifications-panel [data-notification-item]").count();
    expect(dropdownItems).toBeLessThanOrEqual(10);
    await expect(page.getByRole("link", { name: "Show more", exact: true })).toBeVisible();

    await page.mouse.click(30, 30);
    await expect(page.locator(".bc-notifications-panel")).toBeHidden();

    await openNotificationsMenu(page);
    await page.keyboard.press("Escape");
    await expect(page.locator(".bc-notifications-panel")).toBeHidden();

    await openNotificationsMenu(page);
    await page.getByRole("link", { name: "Show more", exact: true }).click();
    await expect(page).toHaveURL(/\/app\/notifications\.php$/);
    await expectSharedSessionHeader(page, "Notifications");
    await expect(page.locator(".bc-sidebar").getByRole("link", { name: "Notifications", exact: true })).toHaveCount(0);
    await expect(page.locator("[data-notifications-page]")).toBeVisible();
  });

  test("shell actions move into the secondary action row", async ({ page }) => {
    await loginAs(page, localAccounts.superAdmin.email, localAccounts.superAdmin.password);
    await page.goto("melvin/project_list.php");

    await expectSharedSessionHeader(page, "Projects");
    await expect(page.locator(".bc-subheader-actions")).toContainText("New Project");
    await expect(page.locator(".bc-subheader-actions")).toContainText("Open Checklist");
    await expect(page.locator(".bc-session")).not.toContainText("New Project");
    await expect(page.locator(".bc-session")).not.toContainText("Open Checklist");
  });

  test("checklist list adds the centralized items table without removing the batch cards", async ({
    page,
  }) => {
    await loginAs(page, localAccounts.user.email, localAccounts.user.password);
    await page.goto("melvin/checklist_list.php?project_id=1");

    const checklistItemsPanel = page.locator("[data-checklist-table]");
    await expectSharedSessionHeader(page, "Checklist");
    await expect(checklistItemsPanel).toBeVisible();
    await expect(checklistItemsPanel).toContainText("Checklist Items");
    await expect(checklistItemsPanel).toContainText("Inline QA Tester actions enabled");
    await expect(checklistItemsPanel.locator("thead")).toContainText("Batch");
    await expect(checklistItemsPanel.locator("thead")).toContainText("Project");
    await expect(checklistItemsPanel.locator("thead")).toContainText("Action");
    await expect(checklistItemsPanel.locator("[data-checklist-assignment-form]").first()).toBeVisible();

    const batchCardsBefore = await page.locator(".bc-list .bc-list-item").count();
    expect(batchCardsBefore).toBeGreaterThan(0);
    await expect(page.locator(".bc-panel").nth(1)).toContainText("Batch status");

    await checklistItemsPanel.locator("#item_assignment").selectOption("unassigned");
    await checklistItemsPanel.getByRole("button", { name: "Apply Filters", exact: true }).click();

    await expect(page).toHaveURL(/project_id=1/);
    await expect(page).toHaveURL(/item_assignment=unassigned/);
    expect(await page.locator(".bc-list .bc-list-item").count()).toBe(batchCardsBefore);

    await checklistItemsPanel.getByRole("link", { name: "Clear Filters", exact: true }).click();
    await expect(page).toHaveURL(/project_id=1/);
    await expect(page).not.toHaveURL(/item_assignment=unassigned/);
    expect(await page.locator(".bc-list .bc-list-item").count()).toBe(batchCardsBefore);
  });

  test("checklist list stays read-only for non-manager roles", async ({ page }) => {
    await loginAs(page, localAccounts.admin.email, localAccounts.admin.password);
    await page.goto("melvin/checklist_list.php");

    const checklistItemsPanel = page.locator("[data-checklist-table]");
    await expectSharedSessionHeader(page, "Checklist");
    await expect(checklistItemsPanel).toBeVisible();
    await expect(checklistItemsPanel).toContainText("Read-only view");
    await expect(checklistItemsPanel.locator("thead")).not.toContainText("Action");
    await expect(checklistItemsPanel.locator("[data-checklist-assignment-form]")).toHaveCount(0);
    await expect(page.locator(".bc-list .bc-list-item").first()).toBeVisible();
  });

  test("checklist assignment updates inline without reloading and shows toasts", async ({
    page,
  }) => {
    await loginAs(page, localAccounts.user.email, localAccounts.user.password);
    await page.goto("melvin/checklist_list.php");

    const checklistItemsPanel = page.locator("[data-checklist-table]");
    const row = checklistItemsPanel.locator("[data-checklist-row]").filter({ hasText: "Unassigned" }).first();
    await expect(row).toBeVisible();

    const select = row.locator('select[name="assigned_to_user_id"]');
    const assigneeLabel = row.locator("[data-checklist-assignee-label]");
    const originalUrl = page.url();
    const originalValue = await select.inputValue();
    const optionValues = await select.locator("option").evaluateAll((nodes) =>
      nodes.map((node) => ({
        value: node.getAttribute("value") || "",
        label: (node.textContent || "").trim(),
      }))
    );
    const targetOption = optionValues.find((option) => option.value !== "" && option.value !== "0" && option.value !== originalValue);
    expect(targetOption).toBeTruthy();

    await select.selectOption(String(targetOption?.value));
    await expect(assigneeLabel).toContainText(String(targetOption?.label));
    await expect(page.locator(".bc-toast-root")).toContainText("Tester assigned");
    await expect(page.locator(".bc-toast-root")).toContainText("QA Tester assignment updated.");
    await expect(page).toHaveURL(originalUrl);

    await row.getByRole("button", { name: "Clear", exact: true }).click();
    await expect(assigneeLabel).toContainText("Unassigned");
    await expect(page.locator(".bc-toast-root")).toContainText("Assignment cleared");
    await expect(page.locator(".bc-toast-root")).toContainText("QA Tester assignment cleared.");
    await expect(page).toHaveURL(originalUrl);
  });

  test("dashboard leaderboard uses 10-item pagination instead of show more", async ({ page }) => {
    await loginAs(page, localAccounts.superAdmin.email, localAccounts.superAdmin.password);

    await page.goto("zen/dashboard.php?page=dashboard");
    await expect(page.locator(".leaderboard-row")).toHaveCount(10);
    await expect(page.getByRole("button", { name: /view more|view less/i })).toHaveCount(0);
    await expect(page.locator(".leaderboard-pagination")).toBeVisible();
    await expect(page.locator(".leaderboard-row").first().locator(".leaderboard-rank")).toHaveText("#1");
    await expect(page.locator(".leaderboard-row").last().locator(".leaderboard-rank")).toHaveText("#10");

    await page.goto("zen/dashboard.php?page=dashboard&ranking_page=2");
    await expect(page.locator(".leaderboard-row").first().locator(".leaderboard-rank")).toHaveText("#11");
  });

  test("notifications dropdown and inbox stay in sync with a live issue notification", async ({ page, request }) => {
    let superAdminToken = "";
    let createdIssueId = 0;
    const notificationTitle = `Legacy notifications ${Date.now()}`;

    try {
      superAdminToken = await apiLogin(request, localAccounts.superAdmin.email, localAccounts.superAdmin.password);

      await loginAs(page, localAccounts.user.email, localAccounts.user.password);
      await page.goto("app/notifications.php");
      await expectSharedSessionHeader(page, "Notifications");

      const markAllButton = page.locator("[data-notifications-mark-all-page]");
      if (await markAllButton.isVisible()) {
        const isDisabled = await markAllButton.isDisabled();
        if (!isDisabled) {
          await markAllButton.click();
          await expect(page.locator(".bc-notifications-badge")).toContainText("0");
        }
      }

      createdIssueId = await apiCreateIssue(request, superAdminToken, notificationTitle);

      await expect
        .poll(async () => {
          const badgeText = await page.locator(".bc-notifications-badge").first().textContent();
          return (badgeText || "").trim();
        })
        .not.toBe("0");

      await openNotificationsMenu(page);
      await expect(page.locator(".bc-notifications-panel")).toContainText(notificationTitle);

      const dropdownLink = page
        .locator(".bc-notifications-panel [data-notification-item]")
        .filter({ hasText: notificationTitle })
        .first();
      await dropdownLink.click();

      await expect(page).toHaveURL(/\/zen\/issue_detail\.php\?id=\d+&org_id=\d+$/);
      await expect(page.locator(".bc-notifications-badge").first()).toContainText("0");

      await page.goto("app/notifications.php");
      await expect(page.locator("[data-notifications-page-list]")).toContainText(notificationTitle);
    } finally {
      await apiDeleteIssue(request, superAdminToken, createdIssueId);
    }
  });

  test("project notifications resolve to the legacy project detail page", async ({ page, request }) => {
    let superAdminToken = "";
    let originalProject: { id: number; name: string; code: string; description: string; status: string } | null = null;
    const suffix = String(Date.now()).slice(-6);
    const updatedName = `Local Parity Project ${suffix}`;

    try {
      superAdminToken = await apiLogin(request, localAccounts.superAdmin.email, localAccounts.superAdmin.password);
      originalProject = await apiFetchProject(request, superAdminToken, legacyProjectId);

      await loginAs(page, localAccounts.user.email, localAccounts.user.password);
      await page.goto("app/notifications.php");
      await expectSharedSessionHeader(page, "Notifications");

      const markAllButton = page.locator("[data-notifications-mark-all-page]");
      if (await markAllButton.isVisible()) {
        const isDisabled = await markAllButton.isDisabled();
        if (!isDisabled) {
          await markAllButton.click();
        }
      }

      await apiPatchProject(request, superAdminToken, legacyProjectId, {
        name: updatedName,
        code: originalProject.code || "",
        description: originalProject.description || "",
        status: originalProject.status || "active",
      });

      await expect
        .poll(async () => {
          const badgeText = await page.locator(".bc-notifications-badge").first().textContent();
          return (badgeText || "").trim();
        })
        .not.toBe("0");

      await openNotificationsMenu(page);
      const projectNotification = page
        .locator(".bc-notifications-panel [data-notification-item]")
        .filter({ hasText: updatedName })
        .first();
      await expect(projectNotification).toBeVisible();
      await projectNotification.click();

      await expect(page).toHaveURL(new RegExp(`/melvin/project_detail\\.php\\?id=${legacyProjectId}&org_id=\\d+$`));
    } finally {
      if (superAdminToken && originalProject) {
        await apiPatchProject(request, superAdminToken, legacyProjectId, {
          name: originalProject.name,
          code: originalProject.code || "",
          description: originalProject.description || "",
          status: originalProject.status || "active",
        });
      }
    }
  });

  test("sidebar keeps the footer visible while nav scrolls independently", async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await loginAs(page, localAccounts.superAdmin.email, localAccounts.superAdmin.password);
    await page.goto("zen/dashboard.php?page=issues&view=kanban&status=all");

    await injectSidebarOverflow(page);

    const sidebarMetrics = await page.locator(".bc-sidebar").evaluate((sidebar) => {
      const style = window.getComputedStyle(sidebar);
      const nav = sidebar.querySelector(".bc-nav");
      const footer = sidebar.querySelector(".bc-userbox");
      const sidebarRect = sidebar.getBoundingClientRect();
      const footerRect = footer?.getBoundingClientRect();

      return {
        position: style.position,
        height: Math.round(sidebarRect.height),
        viewportHeight: window.innerHeight,
        navScrollable: !!nav && nav.scrollHeight > nav.clientHeight,
        navOverflowY: nav ? window.getComputedStyle(nav).overflowY : "",
        footerVisible:
          !!footerRect &&
          footerRect.bottom <= sidebarRect.bottom + 1 &&
          footerRect.top >= sidebarRect.top - 1,
      };
    });

    expect(sidebarMetrics.position).toBe("sticky");
    expect(sidebarMetrics.height).toBe(sidebarMetrics.viewportHeight);
    expect(sidebarMetrics.navScrollable).toBeTruthy();
    expect(["auto", "scroll"]).toContain(sidebarMetrics.navOverflowY);
    expect(sidebarMetrics.footerVisible).toBeTruthy();
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

    await injectSidebarOverflow(page);

    const mobileMetrics = await page.locator(".bc-sidebar").evaluate((sidebar) => {
      const nav = sidebar.querySelector(".bc-nav");
      const footer = sidebar.querySelector(".bc-userbox");
      const sidebarRect = sidebar.getBoundingClientRect();
      const footerRect = footer?.getBoundingClientRect();

      return {
        height: Math.round(sidebarRect.height),
        viewportHeight: window.innerHeight,
        navScrollable: !!nav && nav.scrollHeight > nav.clientHeight,
        footerVisible:
          !!footerRect &&
          footerRect.bottom <= sidebarRect.bottom + 1 &&
          footerRect.top >= sidebarRect.top - 1,
      };
    });

    expect(mobileMetrics.height).toBe(mobileMetrics.viewportHeight);
    expect(mobileMetrics.navScrollable).toBeTruthy();
    expect(mobileMetrics.footerVisible).toBeTruthy();

    await page.keyboard.press("Escape");
    await expect(sidebar).not.toHaveClass(/is-open/);
    await expect(backdrop).not.toHaveClass(/is-visible/);
  });

  test("profile page supports safe username and password validation flows", async ({ page }) => {
    await loginAs(page, localAccounts.user.email, localAccounts.user.password);
    await page.goto("zen/dashboard.php?page=dashboard");

    await openUserMenu(page);
    await page.getByRole("menuitem", { name: "Profile", exact: true }).click();
    await expect(page).toHaveURL(/\/app\/profile\.php$/);

    const usernameInput = page.locator("#profile_username");
    const emailInput = page.locator("#profile_email");
    const currentPasswordInput = page.locator("#current_password");
    const newPasswordInput = page.locator("#password");
    const confirmPasswordInput = page.locator("#confirm_password");
    const profileMessage = page.locator("#profileFormMessage");
    const passwordMessage = page.locator("#passwordFormMessage");

    const originalUsername = await usernameInput.inputValue();
    const updatedUsername = originalUsername.endsWith("x") ? `${originalUsername}qa` : `${originalUsername}x`;

    await expect(page.locator("[data-profile-username]")).toContainText(originalUsername);
    await expect(emailInput).toHaveAttribute("readonly", "");

    await usernameInput.fill(updatedUsername);
    await page.getByRole("button", { name: "Save Profile", exact: true }).click();
    await expect(profileMessage).toContainText("Profile updated successfully.");
    await expect(page.locator("[data-session-username]").first()).toContainText(updatedUsername);
    await expect(page.locator("[data-session-sidebar-username]").first()).toContainText(updatedUsername);
    await expect(page.locator("[data-profile-username]")).toContainText(updatedUsername);
    await expect(page.locator("[data-profile-avatar]")).toContainText(updatedUsername.charAt(0).toUpperCase());

    await usernameInput.fill("m.viner001");
    await page.getByRole("button", { name: "Save Profile", exact: true }).click();
    await expect(profileMessage).toContainText("already used");
    await expect(page.locator("[data-profile-username]")).toContainText(updatedUsername);

    await usernameInput.fill(originalUsername);
    await page.getByRole("button", { name: "Save Profile", exact: true }).click();
    await expect(profileMessage).toContainText("Profile updated successfully.");
    await expect(page.locator("[data-session-username]").first()).toContainText(originalUsername);
    await expect(page.locator("[data-profile-username]")).toContainText(originalUsername);

    await currentPasswordInput.fill("definitely-wrong-password");
    await newPasswordInput.fill("WebTestProd!20260324");
    await confirmPasswordInput.fill("WebTestProd!20260324");
    await page.getByRole("button", { name: "Change Password", exact: true }).click();
    await expect(passwordMessage).toContainText("Current password is incorrect.");
  });
});
