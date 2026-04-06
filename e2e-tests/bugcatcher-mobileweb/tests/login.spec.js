const { test, expect } = require("../../api-v1/node_modules/@playwright/test");
const { cfg } = require("../src/config");

const AUTH_STORAGE_KEY = "bugcatcher-mobileweb-auth-session";
const FRONTEND_API_BASE_PATH = "/api/v1";

function stripByteOrderMark(value) {
  return String(value || "").replace(/^\uFEFF/, "").replace(/^ÃƒÂ¯Ã‚Â»Ã‚Â¿/, "").replace(/^Ã¯Â»Â¿/, "");
}

async function parseJsonResponse(response) {
  return JSON.parse(stripByteOrderMark(await response.text()));
}

function backendApiUrl(pathname) {
  return `${cfg.apiBaseUrl}${cfg.apiBasePath}${pathname.startsWith("/") ? pathname : `/${pathname}`}`;
}

function appApiPattern(pathname) {
  const normalized = pathname.startsWith("/") ? pathname : `/${pathname}`;
  return `**${FRONTEND_API_BASE_PATH}${normalized}`;
}

async function loginViaApi(request, roleKey = "superAdmin") {
  const account = cfg.accounts[roleKey];
  const response = await request.post(backendApiUrl("/auth/login"), {
    data: {
      email: account.email,
      password: account.password,
      active_org_id: cfg.orgId,
    },
  });
  expect(response.status()).toBe(200);
  const body = await parseJsonResponse(response);
  expect(body.ok).toBe(true);
  return body.data;
}

async function buildStoredSession(request, roleKey = "superAdmin") {
  const loginData = await loginViaApi(request, roleKey);
  const meResponse = await request.get(backendApiUrl("/auth/me"), {
    headers: {
      Authorization: `Bearer ${loginData.tokens.access_token}`,
    },
  });
  expect(meResponse.status()).toBe(200);
  const meBody = await parseJsonResponse(meResponse);
  expect(meBody.ok).toBe(true);

  const now = Date.now();
  return {
    accessToken: loginData.tokens.access_token,
    refreshToken: loginData.tokens.refresh_token,
    accessExpiresAt: now + loginData.tokens.access_expires_in * 1000,
    refreshExpiresAt: now + loginData.tokens.refresh_expires_in * 1000,
    activeOrgId: meBody.data.active_org_id,
    user: meBody.data.user,
    memberships: meBody.data.memberships,
  };
}

async function seedLocalStorage(page, value) {
  await page.addInitScript(
    ([storageKey, payload]) => {
      window.localStorage.setItem(storageKey, payload);
    },
    [AUTH_STORAGE_KEY, value],
  );
}

async function fillLogin(page, email, password) {
  await page.locator('input[name="email"]').fill(email);
  await page.locator('input[name="password"]').fill(password);
}

async function submitLogin(page) {
  await page.getByRole("button", { name: "Login" }).click();
}

test("login page renders cleanly with empty fields, auth links, theme toggle, and password eye toggle", async ({ page }) => {
  await page.goto("/login");

  await expect(page.getByRole("heading", { name: "Login" })).toBeVisible();
  await expect(page.getByText("Use your WebTest account", { exact: true })).toBeVisible();
  await expect(page.locator('input[name="email"]')).toHaveValue("");
  await expect(page.locator('input[name="password"]')).toHaveValue("");
  await expect(page.getByRole("button", { name: "Show password" })).toBeVisible();
  await expect(page.getByRole("link", { name: "Sign Up" }).first()).toHaveAttribute("href", "/signup");
  await expect(page.getByRole("link", { name: "Forgot" })).toHaveAttribute("href", "/forgot-password");
  await expect(page.getByRole("button", { name: "Switch to dark mode" })).toBeVisible();

  await page.getByRole("button", { name: "Switch to dark mode" }).click();
  await expect(page.getByRole("button", { name: "Switch to light mode" })).toBeVisible();
  await expect(page.getByRole("alert")).toHaveCount(0);
});

for (const viewport of [
  { name: "320px mobile", width: 320, height: 780 },
  { name: "360px mobile", width: 360, height: 780 },
  { name: "390px mobile", width: 390, height: 844 },
  { name: "414px mobile", width: 414, height: 896 },
  { name: "mobile landscape", width: 844, height: 390 },
]) {
  test(`login layout stays usable at ${viewport.name}`, async ({ page }) => {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await page.goto("/login");

    await expect(page.getByRole("heading", { name: "Login" })).toBeVisible();
    await expect(page.getByRole("button", { name: "Login" })).toBeVisible();

    const widthInfo = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      innerWidth: window.innerWidth,
    }));
    expect(widthInfo.scrollWidth).toBeLessThanOrEqual(widthInfo.innerWidth + 1);
  });
}

test("anonymous app access redirects to login", async ({ page }) => {
  await page.goto("/app");
  await expect(page).toHaveURL(/\/login$/);
  await expect(page.getByRole("heading", { name: "Login" })).toBeVisible();
});

test("successful login redirects to the app, persists the session, and authenticated users cannot reopen login", async ({ page }) => {
  await page.goto("/login");
  await fillLogin(page, cfg.accounts.superAdmin.email, cfg.accounts.superAdmin.password);
  await submitLogin(page);

  await expect(page).toHaveURL(/\/app\/dashboard$/);

  const storedSession = await page.evaluate((storageKey) => {
    const raw = window.localStorage.getItem(storageKey);
    return raw ? JSON.parse(raw) : null;
  }, AUTH_STORAGE_KEY);

  expect(storedSession).toBeTruthy();
  expect(storedSession.accessToken).toBeTruthy();
  expect(storedSession.refreshToken).toBeTruthy();
  expect(storedSession.activeOrgId).toBe(cfg.orgId);

  await page.goto("/login");
  await expect(page).toHaveURL(/\/app\/dashboard$/);
});

for (const scenario of [
  { name: "empty email and password", email: "", password: "", message: "Email and password are required." },
  { name: "missing email", email: "", password: cfg.accounts.superAdmin.password, message: "Email is required." },
  { name: "missing password", email: cfg.accounts.superAdmin.email, password: "", message: "Password is required." },
]) {
  test(`login shows guided validation for ${scenario.name}`, async ({ page }) => {
    await page.goto("/login");
    await fillLogin(page, scenario.email, scenario.password);
    await submitLogin(page);

    await expect(page.getByRole("alert")).toHaveText(scenario.message);
    const storedAuth = await page.evaluate((storageKey) => window.localStorage.getItem(storageKey), AUTH_STORAGE_KEY);
    expect(storedAuth).toBeNull();
  });
}

test("malformed email relies on browser-native validation instead of showing a custom auth error", async ({ page }) => {
  await page.goto("/login");
  await fillLogin(page, "not-an-email", "SomePassword123!");
  await submitLogin(page);

  await expect(page).toHaveURL(/\/login$/);
  await expect(page.getByRole("alert")).toHaveCount(0);

  const validationMessage = await page.locator('input[name="email"]').evaluate((input) => input.validationMessage);
  expect(validationMessage).toBeTruthy();
});

test("unknown email surfaces the guided not-found message and does not persist auth state", async ({ page }) => {
  await page.goto("/login");
  await fillLogin(page, `missing-${Date.now()}@local.dev`, "DevPass123!");
  await submitLogin(page);

  await expect(page.getByRole("alert")).toHaveText("No account found for that email address.");
  const storedAuth = await page.evaluate((storageKey) => window.localStorage.getItem(storageKey), AUTH_STORAGE_KEY);
  expect(storedAuth).toBeNull();
});

test("wrong password surfaces the guided password message and keeps the user on login", async ({ page }) => {
  await page.goto("/login");
  await fillLogin(page, cfg.accounts.superAdmin.email, "wrongpass");
  await submitLogin(page);

  await expect(page).toHaveURL(/\/login$/);
  await expect(page.getByRole("alert")).toHaveText("Incorrect password.");
});

test("email input is trimmed before login submission", async ({ page }) => {
  await page.goto("/login");
  await fillLogin(page, `  ${cfg.accounts.superAdmin.email}  `, cfg.accounts.superAdmin.password);
  await submitLogin(page);

  await expect(page).toHaveURL(/\/app\/dashboard$/);
});

test("pending login disables controls, swaps CTA text, and blocks forgot navigation until the request settles", async ({ page }) => {
  await page.route(appApiPattern("/auth/login"), async (route) => {
    await new Promise((resolve) => setTimeout(resolve, 800));
    await route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({
        ok: true,
        data: {
          user: {
            id: 1,
            username: "superadmin",
            email: cfg.accounts.superAdmin.email,
            role: "super_admin",
          },
          active_org_id: cfg.orgId,
          tokens: {
            access_token: "pending-access-token",
            access_expires_in: 900,
            refresh_token: "pending-refresh-token",
            refresh_expires_in: 2592000,
          },
        },
      }),
    });
  });
  await page.route(appApiPattern("/auth/me"), async (route) => {
    await route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({
        ok: true,
        data: {
          user: {
            id: 1,
            username: "superadmin",
            email: cfg.accounts.superAdmin.email,
            role: "super_admin",
          },
          active_org_id: cfg.orgId,
          memberships: [
            {
              org_id: cfg.orgId,
              org_name: "Local Org",
              role: "owner",
              is_owner: true,
            },
          ],
        },
      }),
    });
  });

  await page.goto("/login");
  await fillLogin(page, cfg.accounts.superAdmin.email, cfg.accounts.superAdmin.password);
  await submitLogin(page);

  await expect(page.getByRole("button", { name: "Signing In..." })).toBeDisabled();
  await expect(page.locator('input[name="email"]')).toBeDisabled();
  await expect(page.locator('input[name="password"]')).toBeDisabled();
  await expect(page.getByRole("button", { name: "Show password" })).toBeDisabled();
  await expect(page.getByRole("link", { name: "Forgot" })).toHaveAttribute("aria-disabled", "true");

  await page.getByRole("link", { name: "Forgot" }).evaluate((element) => element.click());
  await expect(page).toHaveURL(/\/login$/);

  await expect(page).toHaveURL(/\/app\/dashboard$/);
});

test("signup success shows the exact flash copy on the login page with status semantics", async ({ page }) => {
  await page.route(appApiPattern("/auth/signup"), async (route) => {
    await route.fulfill({
      status: 201,
      contentType: "application/json",
      body: JSON.stringify({
        ok: true,
        data: {
          created: true,
          user_id: 999,
          message: "You are registered successfully. You can now login.",
        },
      }),
    });
  });

  await page.goto("/signup");
  await page.locator('input[name="username"]').fill("fresh-user");
  await page.locator('input[name="email"]').fill(`fresh-${Date.now()}@local.dev`);
  await page.locator('input[name="password"]').fill("DevPass123!");
  await page.locator('input[name="confirm_password"]').fill("DevPass123!");
  await page.getByRole("button", { name: "Create" }).click();

  await expect(page).toHaveURL(/\/login$/);
  await expect(page.getByRole("status")).toHaveText("You are registered successfully. You can now login.");
});

test("password visibility toggles work across login, signup, and reset flows", async ({ page }) => {
  await page.goto("/login");
  await page.locator('input[name="password"]').fill("Secret123!");
  await expect(page.locator('input[name="password"]')).toHaveAttribute("type", "password");
  await page.getByRole("button", { name: "Show password" }).click();
  await expect(page.locator('input[name="password"]')).toHaveAttribute("type", "text");
  await page.getByRole("button", { name: "Hide password" }).click();
  await expect(page.locator('input[name="password"]')).toHaveAttribute("type", "password");

  await page.goto("/signup");
  await expect(page.getByRole("button", { name: "Show password" })).toHaveCount(1);
  await expect(page.getByRole("button", { name: "Show confirm" })).toHaveCount(1);
  await page.locator('input[name="password"]').fill("Another123!");
  await page.locator('input[name="confirm_password"]').fill("Another123!");
  await page.getByRole("button", { name: "Show password" }).click();
  await page.getByRole("button", { name: "Show confirm" }).click();
  await expect(page.locator('input[name="password"]')).toHaveAttribute("type", "text");
  await expect(page.locator('input[name="confirm_password"]')).toHaveAttribute("type", "text");

  await page.goto("/forgot-password/verify?email=superadmin%40local.dev");
  await expect(page.getByRole("button", { name: "Show new password" })).toHaveCount(1);
  await expect(page.getByRole("button", { name: "Show confirm" })).toHaveCount(1);
});

for (const scenario of [
  {
    name: "offline or unreachable auth API",
    setup: async (page) => {
      await page.route(appApiPattern("/auth/login"), async (route) => {
        await route.abort("failed");
      });
    },
    message: "Unable to reach the server. Check your connection and try again.",
    rawText: null,
  },
  {
    name: "empty auth response bodies",
    setup: async (page) => {
      await page.route(appApiPattern("/auth/login"), async (route) => {
        await route.fulfill({
          status: 502,
          contentType: "application/json",
          body: "",
        });
      });
    },
    message: "The server returned an empty response. Please try again.",
    rawText: null,
  },
  {
    name: "unexpected non-JSON auth responses",
    setup: async (page) => {
      await page.route(appApiPattern("/auth/login"), async (route) => {
        await route.fulfill({
          status: 500,
          contentType: "text/html",
          body: "<html><body>fatal auth error</body></html>",
        });
      });
    },
    message: "The server returned an unexpected response. Please try again.",
    rawText: "fatal auth error",
  },
]) {
  test(`login shows human-readable feedback for ${scenario.name}`, async ({ page }) => {
    await scenario.setup(page);
    await page.goto("/login");
    await fillLogin(page, cfg.accounts.superAdmin.email, cfg.accounts.superAdmin.password);
    await submitLogin(page);

    await expect(page.getByRole("alert")).toHaveText(scenario.message);
    if (scenario.rawText) {
      await expect(page.getByText(scenario.rawText, { exact: false })).toHaveCount(0);
    }
  });
}

test("login surfaces the exact /auth/me failure message after a successful /auth/login response", async ({ page }) => {
  await page.route(appApiPattern("/auth/login"), async (route) => {
    await route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({
        ok: true,
        data: {
          user: {
            id: 1,
            username: "superadmin",
            email: cfg.accounts.superAdmin.email,
            role: "super_admin",
          },
          active_org_id: cfg.orgId,
          tokens: {
            access_token: "fresh-access-token",
            access_expires_in: 900,
            refresh_token: "fresh-refresh-token",
            refresh_expires_in: 2592000,
          },
        },
      }),
    });
  });
  await page.route(appApiPattern("/auth/me"), async (route) => {
    await route.fulfill({
      status: 401,
      contentType: "application/json",
      body: JSON.stringify({
        ok: false,
        error: {
          code: "session_expired",
          message: "Session expired. Please sign in again.",
        },
      }),
    });
  });

  await page.goto("/login");
  await fillLogin(page, cfg.accounts.superAdmin.email, cfg.accounts.superAdmin.password);
  await submitLogin(page);

  await expect(page.getByRole("alert")).toHaveText("Session expired. Please sign in again.");
  const storedAuth = await page.evaluate((storageKey) => window.localStorage.getItem(storageKey), AUTH_STORAGE_KEY);
  expect(storedAuth).toBeNull();
});

test("corrupt stored auth data falls back to login safely", async ({ page }) => {
  await seedLocalStorage(page, "{bad json");
  await page.goto("/login");

  await expect(page).toHaveURL(/\/login$/);
  await expect(page.getByRole("heading", { name: "Login" })).toBeVisible();
});

test("expired access with a valid refresh token silently restores the session during bootstrap", async ({ page, request }) => {
  const storedSession = await buildStoredSession(request, "superAdmin");
  storedSession.accessToken = "invalid-access-token";
  storedSession.accessExpiresAt = Date.now() - 1000;

  await seedLocalStorage(page, JSON.stringify(storedSession));
  await page.goto("/login");

  await expect(page).toHaveURL(/\/app\/dashboard$/);
});

test("invalid stored tokens are cleared and the user returns to login", async ({ page }) => {
  await seedLocalStorage(
    page,
    JSON.stringify({
      accessToken: "bad-access-token",
      refreshToken: "bad-refresh-token",
      accessExpiresAt: Date.now() - 1000,
      refreshExpiresAt: Date.now() - 1000,
      activeOrgId: cfg.orgId,
    }),
  );
  await page.goto("/login");

  await expect(page).toHaveURL(/\/login$/);
  await expect(page.getByRole("heading", { name: "Login" })).toBeVisible();
  const storedAuth = await page.evaluate((storageKey) => window.localStorage.getItem(storageKey), AUTH_STORAGE_KEY);
  expect(storedAuth).toBeNull();
});
