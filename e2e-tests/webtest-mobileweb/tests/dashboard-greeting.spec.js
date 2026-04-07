const { test, expect } = require("../../api-v1/node_modules/@playwright/test");
const { loginByUi } = require("./helpers");

const roleKeys = [
  "superAdmin",
  "admin",
  "pm",
  "seniorDev",
  "juniorDev",
  "qaTester",
  "seniorQa",
  "qaLead",
];

for (const roleKey of roleKeys) {
  test(`${roleKey} sees the dashboard greeting hero`, async ({ page }) => {
    await loginByUi(page, roleKey);
    await page.goto("/app/dashboard");

    const hero = page.getByTestId("dashboard-greeting-hero");
    await expect(hero).toBeVisible();
    await expect(hero.getByTestId("dashboard-greeting-live-label")).toHaveText(/\[[a-z]{3} \d{1,2}, \d{4} - \d{1,2}:\d{2}(AM|PM)\]/i);
    await expect(hero.getByRole("heading", { name: /Good (morning|afternoon|evening),/i })).toBeVisible();
    await expect(page.getByTestId("dashboard-greeting-message")).toBeVisible();
    await expect(page.getByTestId("dashboard-greeting-mascot")).toBeVisible();
  });
}

test("dashboard greeting hero remains visible after switching to dark mode", async ({ page }) => {
  await loginByUi(page, "pm");
  await page.goto("/app/dashboard");

  const hero = page.getByTestId("dashboard-greeting-hero");
  await expect(hero).toBeVisible();

  await page.getByRole("button", { name: "Switch to dark mode" }).click();
  await expect(page.locator(".site-shell")).toHaveAttribute("data-theme", "dark");
  await expect(hero).toBeVisible();
  await expect(page.getByTestId("dashboard-greeting-mascot")).toBeVisible();
});
