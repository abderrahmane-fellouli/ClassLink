// Frontend-only review QA: no backend process or production API is needed/allowed.
import { chromium, firefox } from "@playwright/test";
import assert from "node:assert/strict";
import { spawn, spawnSync } from "node:child_process";
import { createServer } from "node:net";
import { mkdirSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import { fileURLToPath } from "node:url";
const root = fileURLToPath(new URL("../", import.meta.url));
const port = 18090,
  origin = `http://127.0.0.1:${port}`;
const output = join(root, "artifacts/v2-review");
mkdirSync(output, { recursive: true });
const results = {
  completed: false,
  screens: [],
  viewportChecks: 0,
  roleThemeLocaleChecks: 0,
  apiRequests: [],
  errors: [],
  interactions: [],
};
let server, browser;
const save = () => writeFileSync(join(output, "report.json"), JSON.stringify(results, null, 2));
try {
  await new Promise((resolve, reject) => {
    const probe = createServer();
    probe.once("error", reject);
    probe.listen(port, "127.0.0.1", () => probe.close(resolve));
  });
  server = spawn(
    process.execPath,
    [
      join(root, "frontend/node_modules/vite/bin/vite.js"),
      "preview",
      "--host",
      "127.0.0.1",
      "--port",
      String(port),
      "--strictPort",
    ],
    { cwd: join(root, "frontend"), stdio: "ignore" },
  );
  let ready = false;
  for (let i = 0; i < 60; i++) {
    try {
      if ((await fetch(origin)).ok) {
        ready = true;
        break;
      }
    } catch {}
    await new Promise((r) => setTimeout(r, 250));
  }
  assert.ok(ready, "Frontend preview available");
  for (const [browserName, launcher] of [
    ["chromium", chromium],
    ["firefox", firefox],
  ]) {
    browser = await launcher.launch();
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    await context.addInitScript(() => {
      localStorage.setItem("classlink.locale", "en");
      localStorage.setItem("classlink.theme", "light");
      sessionStorage.setItem("classlink.token", "existing-session-sentinel");
    });
    await context.route("**/*", (route) => {
      const request = route.request(),
        url = new URL(request.url());
      if (["fetch", "xhr"].includes(request.resourceType()) || url.pathname.startsWith("/api/")) {
        results.apiRequests.push({ method: request.method(), url: request.url() });
        return route.abort();
      }
      if (url.origin !== origin) return route.abort();
      return route.continue();
    });
    const page = await context.newPage();
    page.on("pageerror", (error) =>
      results.errors.push({ browser: browserName, message: error.message }),
    );
    page.on("console", (message) => {
      if (
        message.type() === "error" &&
        /TypeError|ReferenceError|React Router caught|Error handled by React Router/.test(
          message.text(),
        )
      )
        results.errors.push({ browser: browserName, message: message.text() });
    });
    await page.goto(origin + "/v2-review");
    await page.locator(".review-toolbar").waitFor();
    const roleNames = {
      student: "Student",
      delegate: "Delegate",
      teacher: "Teacher",
      admin: "Super Admin",
    };
    for (const [role, name] of Object.entries(roleNames)) {
      await page
        .locator(".review-toolbar fieldset")
        .first()
        .getByRole("button", { name, exact: true })
        .click();
      await page.locator(`[data-review-role="${role}"]`).waitFor();
      await page.locator(".app-content h1").first().waitFor();
      const paths = await page.locator("[data-review-screen]").evaluate((el) =>
        Array.from(el.options)
          .map((o) => o.value)
          .filter(Boolean),
      );
      for (const path of paths) {
        await page.locator("[data-review-screen]").selectOption(path);
        await page.waitForTimeout(120);
        assert.equal(
          new URL(page.url()).pathname,
          "/v2-review",
          "Navigation stays on public review URL",
        );
        const body = await page.locator(".app-content").innerText();
        if (role === "delegate" && path === "/app") {
          assert.ok(body.includes("Your delegate responsibility"));
          assert.equal(
            await page.locator('.shell-nav a[href="/app/admin"]').count(),
            0,
            "Delegate has Student navigation, no admin destination",
          );
        }
        assert.ok(
          !body.includes("This action is disabled in visual review"),
          `Missing read fixture: ${role} ${path}`,
        );
        assert.ok(await page.locator(".app-content h1").count(), `Heading: ${role} ${path}`);
        for (const theme of ["light", "dark"]) {
          await page
            .locator(".review-toolbar fieldset")
            .nth(1)
            .getByRole("button", { name: theme === "light" ? "Light" : "Dark", exact: true })
            .click();
          assert.equal(await page.locator("html").getAttribute("data-theme"), theme);
          for (const width of [390, 768, 1440]) {
            await page.setViewportSize({ width, height: 1000 });
            assert.equal(
              await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1),
              true,
              `${browserName} ${role} ${path} ${theme} ${width}`,
            );
            results.viewportChecks++;
          }
          results.screens.push({ browser: browserName, role, path, theme });
        }
        save();
      }
      for (const language of ["FR", "EN"]) {
        await page
          .locator(".review-toolbar fieldset")
          .nth(2)
          .getByRole("button", { name: language, exact: true })
          .click();
        assert.equal(await page.locator("html").getAttribute("lang"), language.toLowerCase());
        for (const width of [390, 768, 1440]) {
          await page.setViewportSize({ width, height: 1000 });
          assert.equal(
            await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1),
            true,
          );
          results.roleThemeLocaleChecks++;
        }
      }
      await page
        .locator("[data-review-screen]")
        .selectOption(role === "admin" ? "/app/admin" : "/app");
      for (const state of ["empty", "loading", "error", "normal"]) {
        await page.locator("[data-review-state]").selectOption(state);
        await page.waitForTimeout(80);
        assert.equal(
          await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1),
          true,
        );
      }
      await page.setViewportSize({ width: 390, height: 1000 });
      await page.screenshot({
        path: join(output, `${browserName}-${role}-390.png`),
        fullPage: true,
      });
      assert.equal(
        await page.evaluate(() => sessionStorage.getItem("classlink.token")),
        "existing-session-sentinel",
      );
      save();
    }
    // Real local-only interaction through the production screen's handlers.
    await page
      .locator(".review-toolbar fieldset")
      .first()
      .getByRole("button", { name: "Teacher", exact: true })
      .click();
    await page.locator("[data-review-screen]").selectOption("/app/school/assessments/401");
    await page.getByLabel("Score Camille Exemple 90001", { exact: true }).fill("0");
    await page.getByRole("button", { name: "Save draft", exact: true }).click();
    await page
      .getByText("Draft saved. Students cannot see these changes yet.", { exact: true })
      .waitFor();
    assert.equal(
      await page.getByLabel("Score Camille Exemple 90001", { exact: true }).inputValue(),
      "0",
    );
    results.interactions.push(`${browserName}: grade save simulated locally`);
    await page
      .getByLabel("Private file", { exact: true })
      .setInputFiles({
        name: "review-grades.csv",
        mimeType: "text/csv",
        buffer: Buffer.from("Synthetic review only"),
      });
    await page.getByRole("button", { name: "Preview file", exact: true }).click();
    await page.getByRole("button", { name: "Confirm draft import", exact: true }).waitFor();
    results.interactions.push(`${browserName}: grade import preview local`);
    await page
      .locator(".review-toolbar fieldset")
      .first()
      .getByRole("button", { name: "Super Admin", exact: true })
      .click();
    await page.locator("[data-review-screen]").selectOption("/app/school");
    await page.getByText("Admissions and roster import", { exact: true }).click();
    await page
      .getByLabel("Authorized roster (CSV)", { exact: true })
      .setInputFiles({
        name: "synthetic-review.csv",
        mimeType: "text/csv",
        buffer: Buffer.from("Synthetic review only"),
      });
    await page.getByRole("button", { name: "Preview file", exact: true }).click();
    await page.locator(".roster-preview-errors").waitFor();
    results.interactions.push(`${browserName}: roster preview local`);
    assert.deepEqual(
      results.apiRequests,
      [],
      "No API requests, including no reads/mutations/auth calls",
    );
    assert.deepEqual(results.errors, [], "No runtime errors");
    await context.close();
    await browser.close();
    browser = null;
  }
  results.completed = true;
  save();
  console.log(
    JSON.stringify(
      {
        completed: true,
        screenThemeVisits: results.screens.length,
        viewportChecks: results.viewportChecks,
        localeChecks: results.roleThemeLocaleChecks,
        apiRequests: results.apiRequests.length,
        runtimeErrors: results.errors.length,
        interactions: results.interactions,
      },
      null,
      2,
    ),
  );
} finally {
  save();
  if (browser) await browser.close();
  if (server) {
    if (process.platform === "win32")
      spawnSync("taskkill", ["/PID", String(server.pid), "/T", "/F"], { stdio: "ignore" });
    else server.kill("SIGTERM");
  }
}
