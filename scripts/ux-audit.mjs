import { chromium, firefox } from "@playwright/test";
import assert from "node:assert/strict";
import { spawn, spawnSync } from "node:child_process";
import { mkdtempSync, mkdirSync, writeFileSync, readFileSync, rmSync, existsSync } from "node:fs";
import { join } from "node:path";
import { tmpdir } from "node:os";
import { fileURLToPath } from "node:url";
import { randomBytes, randomInt } from "node:crypto";
import { createServer } from "node:net";

const root = fileURLToPath(new URL("../", import.meta.url));
const phase = process.argv[2] === "before" ? "before" : "after";
const journeysOnly = process.argv.includes("--journeys-only");
const routesOnly = process.argv.includes("--routes-only");
const resumeReview = process.argv.includes("--resume-review");
const tempRoot = join(tmpdir(), "opencode");
mkdirSync(tempRoot, { recursive: true });
const workspace = mkdtempSync(join(tempRoot, ".school-pilot-ux-"));
const output = join(root, "artifacts/ux", journeysOnly ? "journeys" : phase);
mkdirSync(output, { recursive: true });
const priorReview =
  resumeReview && existsSync(join(output, "review.json"))
    ? JSON.parse(readFileSync(join(output, "review.json"), "utf8"))
    : {};
const reviewKey = (row) => `${row.browser}|${row.actor}|${row.locale}|${row.route}`;
const refreshCopy = (path) =>
  ["/login", "/app/join", "/not-a-real-route"].includes(path) ||
  path.startsWith("/auth/microsoft/callback");
if (resumeReview)
  writeFileSync(join(output, "recheck-history.json"), JSON.stringify(priorReview, null, 2));
let revisited = 0;
const apiBase = "http://127.0.0.1:18011/api",
  frontend = "http://127.0.0.1:18010";
const database = join(workspace, "pilot.sqlite");
writeFileSync(database, "");
const env = {
  ...process.env,
  APP_ENV: "testing",
  APP_DEBUG: "false",
  APP_CONFIG_CACHE: join(workspace, "unused-config.php"),
  APP_URL: "http://127.0.0.1:18011",
  FRONTEND_URL: frontend,
  APP_KEY: randomBytes(32).toString("hex").slice(0, 32),
  DB_CONNECTION: "sqlite",
  DB_URL: "",
  DB_DATABASE: database,
  DB_CACHE_CONNECTION: "sqlite",
  DB_CACHE_LOCK_CONNECTION: "sqlite",
  CACHE_STORE: "database",
  SESSION_DRIVER: "database",
  QUEUE_CONNECTION: "sync",
  FILESYSTEM_DISK: "testing",
  MAIL_MAILER: "array",
  MAIL_URL: "",
  DEV_AUTH_ENABLED: "false",
  VITE_API_URL: apiBase,
};
const processes = [],
  findings = priorReview.findings ?? [],
  screenshots = priorReview.screenshots ?? [],
  journeys = [];
const execute = (args, input) => {
  const result = spawnSync("php", args, {
    cwd: join(root, "backend"),
    env,
    encoding: "utf8",
    input: input === undefined ? undefined : JSON.stringify(input),
  });
  if (result.status !== 0) throw new Error(`Synthetic preparation failed: ${args[0]}`);
};
const ready = async (url) => {
  for (let i = 0; i < 100; i++) {
    try {
      if ((await fetch(url)).ok) return;
    } catch {}
    await new Promise((r) => setTimeout(r, 250));
  }
  throw new Error("Local audit server unavailable");
};
let browser;
let lastPage;
const until = async (loader, label = "Expected committed state") => {
  for (let attempt = 0; attempt < 100; attempt++) {
    const value = await loader();
    if (value) return value;
    await new Promise((resolve) => setTimeout(resolve, 100));
  }
  throw new Error(`${label} was not observed in the isolated audit database`);
};
const recordJourney = (row) => {
  journeys.push(row);
  writeFileSync(
    join(output, "review.json"),
    JSON.stringify({ phase, findings, screenshots, journeys }, null, 2),
  );
  console.log(`JOURNEY ${row.browser}: ${row.scenario}: ${row.result}`);
};
try {
  for (const port of [18010, 18011])
    await new Promise((resolve, reject) => {
      const probe = createServer();
      probe.once("error", () =>
        reject(new Error(`Audit port ${port} is occupied; refusing to reuse another service`)),
      );
      probe.listen(port, "127.0.0.1", () => probe.close(resolve));
    });
  execute(["artisan", "migrate", "--force"]);
  execute(["tests/create-ux-audit-fixtures.php", join(workspace, "actors.json")]);
  const actors = JSON.parse(readFileSync(join(workspace, "actors.json"), "utf8"));
  const call = async (actor, path, method = "GET", data, status = 200) => {
    const response = await fetch(`${apiBase}${path}`, {
      method,
      headers: {
        Accept: "application/json",
        Authorization: `Bearer ${actors[actor].token}`,
        ...(data === undefined ? {} : { "Content-Type": "application/json" }),
      },
      body: data === undefined ? undefined : JSON.stringify(data),
    });
    assert.equal(response.status, status, `${method} ${path}`);
    return response.status === 204 ? null : response.json();
  };
  processes.push(
    spawn("php", ["artisan", "serve", "--host=127.0.0.1", "--port=18011", "--no-reload"], {
      cwd: join(root, "backend"),
      env,
      stdio: "ignore",
    }),
  );
  processes.push(
    spawn(
      process.execPath,
      [
        join(root, "frontend/node_modules/vite/bin/vite.js"),
        "--host",
        "127.0.0.1",
        "--port",
        "18010",
        "--strictPort",
      ],
      { cwd: join(root, "frontend"), env, stdio: "ignore" },
    ),
  );
  await ready("http://127.0.0.1:18011/up");
  await ready(frontend);
  const year = await call(
    "admin",
    "/school/years",
    "POST",
    { name: "Synthetic UX year", starts_on: "2026-01-01", ends_on: "2028-12-31" },
    201,
  );
  const groups = [];
  for (let i = 1; i <= 6; i++)
    groups.push(
      await call(
        "admin",
        "/school/groups",
        "POST",
        {
          academic_year_id: year.id,
          official_code: `SYN-UX${i}`,
          name: `Synthetic group ${i}`,
          filiere: "Synthetic",
          level: "2",
        },
        201,
      ),
    );
  const group = groups[0];
  const mod = await call(
    "admin",
    "/school/modules",
    "POST",
    { code: "SYN-UX", name: "Synthetic learning module" },
    201,
  );
  const offering = await call(
    "admin",
    `/school/groups/${group.id}/offerings`,
    "POST",
    { module_id: mod.id },
    201,
  );
  for (const teacher of ["teacher1", "teacher2"])
    await call(
      "admin",
      `/school/offerings/${offering.id}/teachers`,
      "POST",
      { teacher_id: actors[teacher].id },
      201,
    );
  for (const student of ["student1", "student2"])
    await call(
      "admin",
      `/school/groups/${group.id}/enrollments`,
      "POST",
      { student_id: actors[student].id },
      204,
    );
  let currentDelegate = await call(
    "admin",
    `/school/groups/${group.id}/delegates`,
    "POST",
    { student_id: actors.student1.id, ends_at: "2028-01-31T00:00:00Z" },
    201,
  );
  await call("admin", `/school/groups/${group.id}/activate`, "POST", {});
  await call("admin", `/school/groups/${groups[5].id}/archive`, "POST", {}, 204);
  const tool = (kind, data) =>
    call("teacher1", `/school/offerings/${offering.id}/tools/${kind}`, "POST", data, 201);
  await tool("materials", {
    title: "Synthetic resource with a deliberately long descriptive title for wrapping checks",
    type: "link",
    url: "https://example.invalid/synthetic-resource",
  });
  await tool("announcements", {
    title: "Synthetic module announcement",
    body: "Synthetic class-wide update. Keep private questions in Messages.",
  });
  const assignment = await tool("assignments", {
    title: "Synthetic assignment",
    instructions: "Use the synthetic file only.",
    due_at: "2027-06-01T12:00:00Z",
  });
  await call(
    "teacher1",
    `/school/offerings/${offering.id}/tools/assignments/${assignment.id}/publish`,
    "POST",
    {},
  );
  const quiz = await tool("quizzes", {
    title: "Synthetic practice quiz",
    questions: [
      {
        statement: "Synthetic question",
        type: "single",
        options: [
          { label: "Synthetic correct", is_correct: true },
          { label: "Synthetic alternative", is_correct: false },
        ],
      },
    ],
  });
  await call(
    "teacher1",
    `/school/offerings/${offering.id}/tools/quizzes/${quiz.id}/publish`,
    "POST",
    {},
  );
  const deck = await tool("flashcards", {
    title: "Synthetic flashcards",
    cards: [{ front: "Synthetic prompt", back: "Synthetic answer" }],
  });
  await call(
    "teacher1",
    `/school/offerings/${offering.id}/tools/flashcards/${deck.id}/publish`,
    "POST",
    {},
  );
  const assessment = await call(
    "teacher1",
    `/school/offerings/${offering.id}/assessments`,
    "POST",
    {
      title: "Synthetic official assessment",
      type: "exam",
      assessed_on: "2026-10-08",
      maximum_score: 20,
      coefficient: 1,
    },
    201,
  );
  const draft = await call("teacher1", `/school/assessments/${assessment.id}`);
  await call("teacher1", `/school/assessments/${assessment.id}/draft`, "PUT", {
    version: draft.assessment.version,
    rows: draft.entries.map((r) => ({
      ...r,
      score: 15,
      status: "graded",
      feedback: "Synthetic private feedback",
    })),
  });
  const saved = await call("teacher1", `/school/assessments/${assessment.id}`);
  await call("teacher1", `/school/assessments/${assessment.id}/publish`, "POST", {
    version: saved.assessment.version,
    summary: "Synthetic publication",
  });
  const thread = await call(
    "student2",
    "/school/threads",
    "POST",
    {
      classroom_id: group.id,
      offering_id: offering.id,
      kind: "private_question",
      subject: "Synthetic private conversation",
      body: "Synthetic message",
      participant_ids: [actors.teacher1.id],
    },
    201,
  );
  const draftQuiz = await tool("quizzes", {
    title: "Synthetic draft quiz",
    questions: [
      {
        statement: "Synthetic draft question",
        type: "single",
        options: [
          { label: "Synthetic draft correct", is_correct: true },
          { label: "Synthetic draft alternative", is_correct: false },
        ],
      },
    ],
  });
  const legacy = actors.legacy_id;
  // Explicit route instances cover every route definition, including redirects.
  const publicRoutes = [
    "/",
    "/login",
    "/privacy",
    "/pending",
    "/denied",
    "/auth/microsoft/callback#error=consent",
    "/not-a-real-route",
  ];
  const common = ["/app", "/app/dashboard", "/app/school", "/app/school/messages", "/app/profile"];
  const studentRoutes = [
    ...common,
    "/app/classes",
    `/app/classes/${legacy}`,
    `/app/classes/${group.id}/quizzes/${quiz.id}?offering=${offering.id}`,
    `/app/assignments/${assignment.id}`,
    `/app/flashcard-decks/${deck.id}`,
    "/app/deadlines",
    "/app/partners",
    "/app/progression",
    "/app/join",
    `/app/school/offerings/${offering.id}`,
    "/app/school/grades",
  ];
  const routes = {
    public: publicRoutes,
    student2: [...studentRoutes, `/app/school/messages/${thread.id}`],
    student1: studentRoutes,
    teacher1: [
      ...common,
      "/app/classes",
      `/app/classes/${legacy}/manage`,
      `/app/teacher/classes/${legacy}`,
      "/app/classes/new",
      "/app/requests",
      "/app/progression",
      `/app/classes/${legacy}/manage/quizzes/new`,
      `/app/classes/${group.id}/manage/quizzes/${draftQuiz.id}/edit?offering=${offering.id}`,
      `/app/classes/${group.id}/manage/quizzes/${quiz.id}/results`,
      `/app/classes/${group.id}/manage/assignments/${assignment.id}/grade`,
      `/app/flashcard-decks/${deck.id}`,
      `/app/school/offerings/${offering.id}`,
      `/app/school/assessments/${assessment.id}`,
      `/app/school/messages/${thread.id}`,
    ],
    admin: [
      ...common,
      "/app/admin",
      "/app/admin/users",
      "/app/admin/classes",
      "/app/admin/ai",
      "/app/admin/audit",
    ],
    empty: ["/app", "/app/school/messages", "/app/school/grades", "/app/join"],
    pending: ["/app"],
    suspended: ["/app"],
  };
  const widths =
    phase === "before" ? [320, 768, 1440] : [320, 360, 375, 390, 430, 768, 1024, 1280, 1440, 1920];
  for (const [browserName, launcher] of phase === "before"
    ? [["chromium", chromium]]
    : [
        ["chromium", chromium],
        ["firefox", firefox],
      ]) {
    // Independent browser test cases must not share OTP/IP throttle budgets.
    // env pins this command to the disposable SQLite database cache only.
    execute(["artisan", "cache:clear"]);
    browser = await launcher.launch();
    for (const [actor, paths] of Object.entries(journeysOnly ? {} : routes)) {
      const context = await browser.newContext();
      await context.route("https://fonts.googleapis.com/**", (route) => route.abort());
      await context.addInitScript(
        ({ token }) => {
          if (token) sessionStorage.setItem("classlink.token", token);
          if (!localStorage.getItem("classlink.locale"))
            localStorage.setItem("classlink.locale", "fr");
        },
        { token: actors[actor]?.token },
      );
      const page = await context.newPage();
      let errors = [];
      page.on("pageerror", (e) => errors.push(e.name));
      for (const locale of phase === "before" ? ["fr"] : ["fr", "en"]) {
        if (actors[actor] && !["pending", "suspended"].includes(actor))
          await call(actor, "/me", "PATCH", { locale });
        for (const [index, path] of paths.entries()) {
          const existing = findings.find(
            (row) =>
              reviewKey(row) === reviewKey({ browser: browserName, actor, locale, route: path }),
          );
          if (
            resumeReview &&
            existing &&
            !existing.errors.length &&
            !existing.overflows.length &&
            !refreshCopy(path)
          )
            continue;
          revisited++;
          await page.goto(frontend + path);
          await page.evaluate((locale) => localStorage.setItem("classlink.locale", locale), locale);
          await page.reload();
          await page.waitForLoadState("networkidle", { timeout: 10000 });
          const row = {
            browser: browserName,
            actor,
            locale,
            route: path,
            finalRoute: new URL(page.url()).pathname,
            heading: await page
              .locator("h1")
              .first()
              .textContent({ timeout: 1000 })
              .catch(() => ""),
            errors: [...errors],
            overflows: [],
            tabs: [],
          };
          errors = [];
          for (const width of widths) {
            await page.setViewportSize({ width, height: 900 });
            const overflow = await page.evaluate(
              () => document.documentElement.scrollWidth > innerWidth + 1,
            );
            if (overflow) row.overflows.push(width);
            const representative =
              index === 0 &&
              ["public", "student1", "student2", "teacher1", "admin"].includes(actor);
            const firefoxDetail =
              browserName === "firefox" &&
              /\/school\/(grades|assessments)/.test(path) &&
              [320, 768, 1440].includes(width);
            if (
              locale === "fr" &&
              !["pending", "suspended"].includes(actor) &&
              ((browserName === "chromium" && [320, 768, 1440].includes(width)) ||
                representative ||
                firefoxDetail)
            ) {
              await page.evaluate(() => window.scrollTo(0, 0));
              const name = `${browserName === "firefox" ? "firefox-" : ""}${actor}-${index}-${width}.png`;
              await page.screenshot({ path: join(output, name), fullPage: true });
              if (!screenshots.includes(name)) screenshots.push(name);
            }
          }
          const tabs = page.getByRole("tab");
          for (let i = 0; i < (await tabs.count()); i++) {
            await tabs.nth(i).click();
            await page.waitForLoadState("networkidle");
            row.tabs.push(await tabs.nth(i).textContent());
            for (const width of widths) {
              await page.setViewportSize({ width, height: 900 });
              if (await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1))
                row.overflows.push(`tab-${i}-${width}`);
            }
          }
          row.errors.push(...errors);
          errors = [];
          const existingIndex = findings.findIndex(
            (previous) => reviewKey(previous) === reviewKey(row),
          );
          if (existingIndex >= 0) findings[existingIndex] = row;
          else findings.push(row);
          writeFileSync(
            join(output, "review.json"),
            JSON.stringify({ phase, findings, screenshots, journeys }, null, 2),
          );
        }
      }
      await context.close();
    }
    if (phase === "after" && !routesOnly) {
      const actorPage = async (actor, authenticate = true) => {
        const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
        await context.route("https://fonts.googleapis.com/**", (route) => route.abort());
        await context.addInitScript(
          (token) => {
            if (token) sessionStorage.setItem("classlink.token", token);
            if (!localStorage.getItem("classlink.locale"))
              localStorage.setItem("classlink.locale", "en");
          },
          authenticate ? actors[actor].token : null,
        );
        lastPage = await context.newPage();
        return { context, page: lastPage };
      };
      const assertCleanError = async (page) => {
        const text = await page.locator("body").innerText();
        assert.doesNotMatch(
          text,
          /SQLSTATE|PDOException|Stack trace|private-provider-host|secret-fixture-value/,
        );
        assert.equal(
          await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1),
          true,
        );
      };
      const capture = async (page, name) => {
        await page.evaluate(() => window.scrollTo(0, 0));
        const file = `${browserName}-${name}.png`;
        await page.screenshot({ path: join(output, file), fullPage: true });
        screenshots.push(file);
      };
      const { context: adminContext, page: adminPage } = await actorPage("admin");
      await adminPage.goto(`${frontend}/app/school`);
      await adminPage.waitForLoadState("networkidle");
      await adminPage.getByText("Years, groups and module catalogue", { exact: true }).click();
      const newGroupName = `Synthetic journey group ${browserName}`;
      const groupForm = adminPage
        .locator("form")
        .filter({ has: adminPage.locator('input[name="official_code"]') });
      await groupForm.locator("..").locator("summary").click();
      await groupForm.locator('select[name="academic_year_id"]').selectOption(String(year.id));
      await groupForm.locator('input[name="official_code"]').fill(`SYN-${browserName}`);
      await groupForm.locator('input[name="name"]').fill(newGroupName);
      await groupForm.locator('input[name="filiere"]').fill("Synthetic stream");
      await groupForm.locator('input[name="level"]').fill("2");
      await groupForm.getByRole("button", { name: "Create", exact: true }).click();
      await adminPage.getByRole("button", { name: new RegExp(newGroupName) }).click();
      const createdGroup = await until(
        async () => (await call("admin", "/school")).groups.find((g) => g.name === newGroupName),
        "Group creation",
      );
      assert.ok(createdGroup);
      const moduleForm = adminPage
        .locator("form")
        .filter({ has: adminPage.locator('select[name="module_id"]') });
      await moduleForm.locator('select[name="module_id"]').selectOption(String(mod.id));
      await moduleForm.getByRole("button", { name: "Assign", exact: true }).click();
      await adminPage.waitForLoadState("networkidle");
      const newOffering = await until(
        async () =>
          (await call("admin", "/school")).offerings.find(
            (o) => o.classroom_id === createdGroup.id,
          ),
        "Module assignment",
      );
      const teacherForm = adminPage
        .locator("form")
        .filter({ has: adminPage.locator('select[name="teacher_id"]') });
      await teacherForm
        .locator('select[name="teacher_id"]')
        .selectOption(String(actors.teacher1.id));
      await teacherForm.getByRole("button", { name: "Assign", exact: true }).click();
      await adminPage.waitForLoadState("networkidle");
      await until(
        async () =>
          (await call("admin", "/school")).teachers.some(
            (t) => t.offering_id === newOffering.id && t.teacher_id === actors.teacher1.id,
          ),
        "Teacher assignment",
      );
      // Check the admin setting without granting any extra role to a delegate.
      await adminPage.getByText("Group settings and access", { exact: true }).click();
      await adminPage.getByRole("switch", { name: "Allow delegate announcements" }).click();
      await adminPage.waitForLoadState("networkidle");
      await until(
        async () =>
          (await call("admin", "/school")).groups.find((g) => g.id === createdGroup.id)
            ?.delegate_notices_enabled === true,
        "Delegate setting",
      );
      await adminPage.getByText("Admissions and roster import", { exact: true }).click();
      const admissionForm = adminPage
        .locator("form")
        .filter({ has: adminPage.getByRole("button", { name: "Enroll", exact: true }) });
      await admissionForm
        .locator('select[name="student_id"]')
        .selectOption(String(actors.empty.id));
      await admissionForm.getByRole("button", { name: "Enroll", exact: true }).click();
      await adminPage.waitForLoadState("networkidle");
      await until(
        async () =>
          (await call("admin", `/school/groups/${createdGroup.id}/roster`)).data.some(
            (r) => r.student_id === actors.empty.id,
          ),
        "Student admission",
      );
      const importedName = `Synthetic roster ${browserName}`;
      await adminPage.getByLabel("Authorized roster (CSV)", { exact: true }).setInputFiles({
        name: "synthetic-roster.csv",
        mimeType: "text/csv",
        buffer: Buffer.from(
          `student_identifier;email;display_name\nSYN-${browserName};synthetic.import.${browserName}@ofppt-edu.ma;${importedName}\n`,
        ),
      });
      await adminPage.getByRole("button", { name: "Preview file", exact: true }).click();
      await adminPage
        .getByRole("button", { name: "Confirm roster and admissions", exact: true })
        .click();
      await adminPage
        .getByRole("dialog")
        .getByRole("button", { name: "Confirm", exact: true })
        .click();
      await adminPage.waitForLoadState("networkidle");
      await until(
        async () =>
          (await call("admin", `/school/groups/${createdGroup.id}/roster`)).data.some(
            (r) => r.display_name === importedName,
          ),
        "Roster import",
      );
      const delegateForm = adminPage
        .locator("form")
        .filter({ has: adminPage.getByRole("button", { name: "Appoint delegate", exact: true }) });
      await delegateForm.locator('select[name="student_id"]').selectOption(String(actors.empty.id));
      await delegateForm.locator('input[name="ends_at"]').fill("2027-06-01");
      await delegateForm.getByRole("button", { name: "Appoint delegate", exact: true }).click();
      await adminPage.waitForLoadState("networkidle");
      await until(
        async () =>
          (await call("admin", "/school")).delegates.some(
            (d) => d.classroom_id === createdGroup.id && d.student_id === actors.empty.id,
          ),
        "Delegate appointment",
      );
      const emptyRow = adminPage.locator("li").filter({ hasText: "Synthetic empty" });
      await emptyRow.getByRole("button", { name: "Remove from group", exact: true }).click();
      await adminPage
        .getByRole("dialog")
        .getByRole("button", { name: "Confirm", exact: true })
        .click();
      await adminPage.waitForLoadState("networkidle");
      await until(
        async () =>
          !(await call("admin", `/school/groups/${createdGroup.id}/roster`)).data.some(
            (r) => r.student_id === actors.empty.id,
          ),
        "Enrollment removal",
      );
      recordJourney({
        browser: browserName,
        scenario: "D — admin UI group creation, module/teacher assignment and delegate setting",
        result: "passed",
      });
      await adminContext.close();

      const { context: teacherContext, page: teacherPage } = await actorPage("teacher1");
      const nextScore = browserName === "chromium" ? 17 : 18;
      await teacherPage.goto(`${frontend}/app/school/offerings/${offering.id}`);
      const resourceForm = teacherPage
        .locator("form")
        .filter({ has: teacherPage.locator('input[name="url"]') });
      await resourceForm
        .locator('input[name="title"]')
        .fill(`Synthetic UI resource ${browserName}`);
      await resourceForm.locator('input[name="url"]').fill("https://example.invalid/synthetic");
      await resourceForm.getByRole("button", { name: "Create", exact: true }).click();
      await teacherPage.waitForLoadState("networkidle");
      await until(
        async () =>
          (await call("teacher1", `/school/offerings/${offering.id}/tools/materials`)).data.some(
            (r) => r.title === `Synthetic UI resource ${browserName}`,
          ),
        "Resource creation",
      );
      await teacherPage.getByRole("tab", { name: "Assignment", exact: true }).click();
      const assignmentForm = teacherPage
        .locator("form")
        .filter({ has: teacherPage.locator('textarea[name="instructions"]') });
      await assignmentForm
        .locator('input[name="title"]')
        .fill(`Synthetic UI assignment ${browserName}`);
      await assignmentForm
        .locator('textarea[name="instructions"]')
        .fill("Upload a synthetic text file.");
      await assignmentForm.locator('input[name="due_at"]').fill("2027-06-15T12:00");
      await assignmentForm.getByRole("button", { name: "Create", exact: true }).click();
      await teacherPage.waitForLoadState("networkidle");
      const uiAssignment = await until(
        async () =>
          (await call("teacher1", `/school/offerings/${offering.id}/tools/assignments`)).data.find(
            (a) => a.title === `Synthetic UI assignment ${browserName}`,
          ),
        "Assignment creation",
      );
      assert.ok(uiAssignment);
      assert.equal(new Date(uiAssignment.due_at).toISOString(), "2027-06-15T11:00:00.000Z");
      const assignmentCard = teacherPage.locator("article").filter({
        has: teacherPage.getByRole("heading", { name: uiAssignment.title, exact: true }),
      });
      await assignmentCard.getByRole("button", { name: "Publish assignment", exact: true }).click();
      await teacherPage
        .getByRole("dialog")
        .getByRole("button", { name: "Confirm", exact: true })
        .click();
      await assignmentCard
        .getByRole("button", { name: "Close submissions", exact: true })
        .waitFor();
      await teacherPage.goto(`${frontend}/app/school/assessments/${assessment.id}`);
      await teacherPage.getByLabel("Correction reason").fill(`Synthetic correction ${browserName}`);
      await teacherPage.getByRole("button", { name: "Create correction", exact: true }).click();
      await teacherPage
        .getByLabel(`Score Synthetic student2 ${actors.student2.id}`)
        .fill(String(nextScore));
      await teacherPage.getByRole("button", { name: "Save draft", exact: true }).click();
      await teacherPage.waitForLoadState("networkidle");
      assert.equal(
        Number((await call("student2", "/school/my-grades")).data[0].score),
        browserName === "chromium" ? 15 : 17,
      );
      await teacherPage
        .getByLabel("Publication summary")
        .fill(`Synthetic reviewed publication ${browserName}`);
      await teacherPage.getByRole("button", { name: "Publish results", exact: true }).click();
      await teacherPage
        .getByRole("dialog")
        .getByRole("button", { name: "Confirm", exact: true })
        .click();
      await teacherPage.getByRole("button", { name: "Create correction", exact: true }).waitFor();
      assert.equal(Number((await call("student2", "/school/my-grades")).data[0].score), nextScore);
      recordJourney({
        browser: browserName,
        scenario: "C — teacher resource creation, correction draft, save and private publication",
        result: "passed",
      });
      await teacherContext.close();

      const { context: studentContext, page: studentPage } = await actorPage("student2", false);
      await studentPage.goto(`${frontend}/login`);
      await studentPage.getByRole("button", { name: /email code/i }).click();
      await studentPage.getByLabel("School address", { exact: true }).fill(actors.student2.email);
      await studentPage.getByRole("button", { name: "Send the code", exact: true }).click();
      await studentPage.locator('input[autocomplete="one-time-code"]').waitFor();
      const credential = String(randomInt(100000, 1000000));
      execute(["tests/prepare-ux-otp.php"], { email: actors.student2.email, code: credential });
      await studentPage.locator('input[autocomplete="one-time-code"]').fill(credential);
      await studentPage.getByRole("button", { name: /verify/i }).click();
      await studentPage.waitForURL(`${frontend}/app`);
      await studentPage.goto(`${frontend}/app/assignments/${uiAssignment.id}`);
      await studentPage.locator('input[type="file"]').setInputFiles({
        name: "synthetic-work.txt",
        mimeType: "text/plain",
        buffer: Buffer.from("Synthetic assignment work only."),
      });
      await studentPage
        .getByRole("button", { name: /Submit|Send/i })
        .first()
        .click();
      await studentPage.waitForLoadState("networkidle");
      await until(
        async () => (await call("student2", `/assignments/${uiAssignment.id}`)).my_submission,
        "Submission storage",
      );
      await studentPage.goto(`${frontend}/app/school/grades`);
      await studentPage.getByRole("heading", { name: /Synthetic learning module/ }).waitFor();
      assert.ok((await studentPage.locator("main").textContent()).includes(String(nextScore)));
      await studentPage.goto(`${frontend}/app/school/messages?group=${group.id}`);
      await studentPage.getByLabel("Modules", { exact: true }).selectOption(String(offering.id));
      await studentPage
        .getByLabel("Recipient", { exact: true })
        .selectOption(String(actors.teacher1.id));
      await studentPage
        .getByLabel("Subject", { exact: true })
        .first()
        .fill(`Synthetic UI question ${browserName}`);
      await studentPage
        .getByLabel("Message", { exact: true })
        .first()
        .fill("Synthetic private question only.");
      await studentPage.getByRole("button", { name: "Send", exact: true }).first().click();
      await studentPage.waitForURL(/\/app\/school\/messages\/\d+/);
      const messageId = Number(new URL(studentPage.url()).pathname.split("/").at(-1));
      assert.equal((await call("student2", `/school/threads/${messageId}`)).participants.length, 2);
      await call("student1", `/school/threads/${messageId}`, "GET", undefined, 404);
      recordJourney({
        browser: browserName,
        scenario: "A — student published result, UI private question and nonparticipant rejection",
        result: "passed",
      });
      await studentPage.goto(`${frontend}/app/flashcard-decks/${deck.id}`);
      await studentPage.getByRole("button", { name: /reveal the answer/i }).click();
      await studentPage.getByRole("button", { name: /know/i }).first().click();
      await studentPage.waitForLoadState("networkidle");
      await until(
        async () => (await call("student2", `/flashcard-decks/${deck.id}`)).cards[0].known === true,
        "Flashcard review",
      );
      const practice = await tool("quizzes", {
        title: `Synthetic journey practice ${browserName}`,
        questions: [
          {
            statement: "Synthetic practice question",
            type: "single",
            options: [
              { label: "Synthetic correct choice", is_correct: true },
              { label: "Synthetic wrong choice", is_correct: false },
            ],
          },
        ],
      });
      await call(
        "teacher1",
        `/school/offerings/${offering.id}/tools/quizzes/${practice.id}/publish`,
        "POST",
        {},
      );
      await studentPage.goto(
        `${frontend}/app/classes/${group.id}/quizzes/${practice.id}?offering=${offering.id}`,
      );
      await studentPage.getByRole("button", { name: /Start/i }).first().click();
      await studentPage
        .getByRole("button", { name: "Synthetic correct choice", exact: true })
        .click();
      await studentPage.getByRole("button", { name: /Submit/i }).click();
      await studentPage
        .getByRole("dialog")
        .getByRole("button", { name: "Confirm", exact: true })
        .click();
      await studentPage.getByRole("heading", { name: "Quiz finished!", exact: true }).waitFor();
      assert.ok(
        (await call("student2", "/me/progress")).history.some(
          (a) => a.quiz_id === practice.id && a.percentage === 100,
        ),
      );
      await call("student1", "/me/partner-profile", "PUT", {
        opt_in: true,
        skills: ["Synthetic study skill"],
        availability: [],
      });
      await studentPage.goto(`${frontend}/app/partners`);
      await studentPage
        .getByRole("combobox", { name: "Class", exact: true })
        .selectOption(String(group.id));
      await studentPage.getByRole("button", { name: "Send a request", exact: true }).click();
      await studentPage.waitForLoadState("networkidle");
      const partnerRequest = await until(
        async () =>
          (await call("student2", "/me/partner-requests")).data.find(
            (r) => r.counterpart.id === actors.student1.id && r.status === "pending",
          ),
        "Partner request",
      );
      assert.ok(partnerRequest?.is_official);
      const { context: partnerContext, page: partnerPage } = await actorPage("student1");
      await partnerPage.goto(`${frontend}/app/partners?tab=requests`);
      await partnerPage.getByRole("button", { name: "Accept", exact: true }).click();
      await partnerPage.waitForLoadState("networkidle");
      await until(
        async () =>
          (await call("student2", "/me/partner-requests")).data.find(
            (r) => r.id === partnerRequest.id,
          )?.status === "accepted",
        "Partner response",
      );
      await partnerContext.close();
      lastPage = studentPage;
      recordJourney({
        browser: browserName,
        scenario:
          "F — flashcard review, real quiz result persistence and official partner request/accept",
        result: "passed",
      });
      // Recover a transport failure without deleting the authentication token.
      await studentPage.route("**/api/school", (route) => route.abort());
      await studentPage.goto(`${frontend}/app/school`);
      await studentPage.getByRole("button", { name: "Try again", exact: true }).first().waitFor();
      await studentPage.unroute("**/api/school");
      await studentPage.getByRole("button", { name: "Try again", exact: true }).first().click();
      await studentPage.getByRole("button", { name: /Synthetic group 1/ }).waitFor();
      assert.ok(await studentPage.evaluate(() => sessionStorage.getItem("classlink.token")));
      const recoveryChecks = ["temporary network failure", "retry retains authenticated access"];
      for (const locale of ["fr", "en"]) {
        await call("student2", "/me", "PATCH", { locale });
        await studentPage.evaluate(
          (locale) => localStorage.setItem("classlink.locale", locale),
          locale,
        );
        for (const status of [403, 404, 429, 500, 503]) {
          const friendly =
            locale === "fr"
              ? `Accès temporairement indisponible (${status}). Réessayez ou revenez à votre espace.`
              : `This resource is unavailable (${status}). Retry or return to your workspace.`;
          await studentPage.route("**/api/school", (route) =>
            route.fulfill({
              status,
              json: {
                message:
                  status >= 500 ? "SQLSTATE private-provider-host secret-fixture-value" : friendly,
              },
              headers: status === 429 ? { "Retry-After": "1" } : {},
            }),
          );
          await studentPage.goto(`${frontend}/app/school`);
          const retry = studentPage
            .getByRole("button", { name: locale === "fr" ? "Réessayer" : "Try again", exact: true })
            .first();
          await retry.waitFor();
          await studentPage.setViewportSize({ width: 320, height: 900 });
          await assertCleanError(studentPage);
          await capture(studentPage, `recovery-${locale}-${status}-320`);
          await studentPage.unroute("**/api/school");
          await retry.click();
          await studentPage.getByRole("button", { name: /Synthetic group 1/ }).waitFor();
          recoveryChecks.push(`${locale}: ${status} safe error and retry`);
        }
      }
      await call("student2", "/me", "PATCH", { locale: "en" });
      await studentPage.evaluate(() => localStorage.setItem("classlink.locale", "en"));
      // A read notification with a removed private target must recover to the inbox.
      const existingNotification = (await call("student2", "/notifications")).data[0];
      await studentPage.route("**/api/notifications", (route) =>
        route.fulfill({
          json: {
            data: [
              {
                ...existingNotification,
                type: "school_message_received",
                is_read: true,
                payload: { url: "/app/school/messages/999999" },
              },
            ],
            unread_count: 0,
          },
        }),
      );
      await studentPage.goto(`${frontend}/app/school`);
      await studentPage
        .getByRole("button", { name: "Notifications", exact: true })
        .filter({ visible: true })
        .first()
        .click();
      const notificationDialog = studentPage.getByRole("dialog");
      await notificationDialog.getByRole("button", { name: /new message is available/i }).click();
      await studentPage.waitForURL(/\/messages\/999999$/);
      await studentPage.getByRole("button", { name: "Try again", exact: true }).waitFor();
      await assertCleanError(studentPage);
      await studentPage
        .getByRole("link", { name: "Messages", exact: true })
        .filter({ visible: true })
        .last()
        .click();
      await studentPage.waitForURL(`${frontend}/app/school/messages`);
      await studentPage.unroute("**/api/notifications");
      recoveryChecks.push("removed notification target: safe 404 and inbox return");
      // Expire only the browser's real synthetic OTP session, not the API fixture token.
      const resumePath = `/app/school/messages?group=${group.id}`;
      await studentPage.goto(frontend + resumePath);
      const tokenId = await studentPage.evaluate(() =>
        Number(sessionStorage.getItem("classlink.token").split("|")[0]),
      );
      execute(["tests/expire-ux-token.php"], { user_id: actors.student2.id, token_id: tokenId });
      await studentPage.reload();
      await studentPage.waitForURL(`${frontend}/login`);
      assert.equal(
        await studentPage.evaluate(() => sessionStorage.getItem("classlink.token")),
        null,
      );
      await capture(studentPage, "expired-session-login-320");
      await studentPage.getByRole("button", { name: /email code/i }).click();
      await studentPage.getByLabel("School address", { exact: true }).fill(actors.student2.email);
      await studentPage.getByRole("button", { name: "Send the code", exact: true }).click();
      const codeInput = studentPage.locator('input[autocomplete="one-time-code"]');
      await codeInput.waitFor();
      const renewed = String(randomInt(100000, 1000000));
      execute(["tests/prepare-ux-otp.php"], { email: actors.student2.email, code: renewed });
      await codeInput.fill(String((Number(renewed) + 1) % 1000000).padStart(6, "0"));
      await studentPage.getByRole("button", { name: /verify/i }).click();
      await studentPage.getByRole("alert").waitFor();
      assert.equal(new URL(studentPage.url()).pathname, "/login");
      await codeInput.fill(renewed);
      await studentPage.getByRole("button", { name: /verify/i }).click();
      await studentPage.waitForURL(frontend + resumePath);
      await studentPage.getByRole("heading", { name: "Messages", exact: true }).waitFor();
      recoveryChecks.push(
        "real session expiry: 401 clears token",
        "invalid OTP stays on login",
        "OTP re-login resumes original workflow",
      );
      // Exercise failed forms and uploads against the same real teacher module UI.
      const { context: failureContext, page: failurePage } = await actorPage("teacher1");
      await failurePage.goto(`${frontend}/app/school/offerings/${offering.id}`);
      const failedForm = failurePage
        .locator("form")
        .filter({ has: failurePage.locator('input[name="url"]') });
      const recoveryTitle = `Synthetic recovered form ${browserName}`;
      await failedForm.locator('input[name="title"]').fill(recoveryTitle);
      await failedForm.locator('input[name="url"]').fill("https://example.invalid/recovery");
      const materialUrl = `**/api/school/offerings/${offering.id}/tools/materials`;
      await failurePage.route(materialUrl, (route) =>
        route.request().method() === "POST"
          ? route.fulfill({
              status: 422,
              json: {
                message: "Review the title field.",
                errors: { title: ["Use a descriptive resource title."] },
              },
            })
          : route.continue(),
      );
      await failedForm.getByRole("button", { name: "Create", exact: true }).click();
      await failurePage.getByText("Use a descriptive resource title.", { exact: true }).waitFor();
      assert.equal(await failedForm.locator('input[name="title"]').inputValue(), recoveryTitle);
      await failurePage.unroute(materialUrl);
      await failedForm.getByRole("button", { name: "Create", exact: true }).click();
      await until(
        async () =>
          (await call("teacher1", `/school/offerings/${offering.id}/tools/materials`)).data.some(
            (r) => r.title === recoveryTitle,
          ),
        "Form retry",
      );
      recoveryChecks.push("422 field validation preserves input; successful retry persists result");
      const fileTitle = `Synthetic recovered upload ${browserName}`;
      const contentSection = failurePage
        .getByRole("heading", { name: "Add module content", exact: true })
        .locator("..");
      await contentSection.locator('input:not([name]):not([type="file"])').fill(fileTitle);
      await contentSection.locator('input[type="file"]').setInputFiles({
        name: "synthetic-recovery.txt",
        mimeType: "text/plain",
        buffer: Buffer.from("Synthetic retry upload content."),
      });
      await failurePage.route(materialUrl, (route) =>
        route.request().method() === "POST"
          ? route.fulfill({
              status: 503,
              json: {
                message: "SQLSTATE private-provider-host secret-fixture-value",
                errors: { title: ["SQLSTATE private-provider-host"] },
              },
            })
          : route.continue(),
      );
      await contentSection.getByRole("button", { name: "Create", exact: true }).nth(1).click();
      await failurePage.getByRole("alert").waitFor();
      await assertCleanError(failurePage);
      assert.equal(
        await contentSection.locator('input:not([name]):not([type="file"])').inputValue(),
        fileTitle,
      );
      await failurePage.unroute(materialUrl);
      await contentSection.getByRole("button", { name: "Create", exact: true }).nth(1).click();
      await until(
        async () =>
          (await call("teacher1", `/school/offerings/${offering.id}/tools/materials`)).data.some(
            (r) => r.title === fileTitle && r.has_file,
          ),
        "Upload retry",
      );
      recoveryChecks.push("503 failed upload hides internals, retains input and retries safely");
      await failureContext.close();
      lastPage = studentPage;
      recordJourney({
        browser: browserName,
        scenario:
          "E — network, HTTP failures, expired session, invalid OTP, re-login, missing target and upload/form recovery",
        result: "passed",
        checks: recoveryChecks,
      });
      await studentContext.close();

      const { context: delegateContext, page: delegatePage } = await actorPage("student1");
      await call(
        "admin",
        `/school/groups/${group.id}`,
        "PATCH",
        { delegate_notices_enabled: true },
        204,
      );
      await delegatePage.goto(`${frontend}/app/school`);
      await delegatePage.getByText("Your delegate responsibility", { exact: true }).waitFor();
      await delegatePage.setViewportSize({ width: 320, height: 900 });
      await capture(delegatePage, "delegate-badge-320");
      await delegatePage.getByRole("link", { name: "Class representation", exact: true }).click();
      await delegatePage.waitForURL(`${frontend}/app/school/messages`);
      await delegatePage.goto(`${frontend}/app/school/messages?group=${group.id}`);
      await delegatePage.getByLabel("Type", { exact: true }).selectOption("organization");
      await delegatePage
        .getByLabel("Recipient", { exact: true })
        .selectOption(String(actors.admin.id));
      await delegatePage
        .getByLabel("Subject", { exact: true })
        .first()
        .fill(`Synthetic delegate request ${browserName}`);
      await delegatePage
        .getByLabel("Message", { exact: true })
        .first()
        .fill("Synthetic organizational request, no private student data.");
      await delegatePage.getByRole("button", { name: "Send", exact: true }).first().click();
      await delegatePage.waitForURL(/\/app\/school\/messages\/\d+/);
      const delegateThread = Number(new URL(delegatePage.url()).pathname.split("/").at(-1));
      assert.equal(
        (await call("admin", `/school/threads/${delegateThread}`)).thread.kind,
        "organization",
      );
      await call("student2", `/school/threads/${delegateThread}`, "GET", undefined, 404);
      const delegateChecks = [
        "student home and delegate badge/navigation",
        "delegate → admin private organization request",
      ];
      await delegatePage.goto(`${frontend}/app/school/messages?group=${group.id}`);
      await delegatePage.getByLabel("Type", { exact: true }).selectOption("representation");
      await delegatePage
        .getByLabel("Recipient", { exact: true })
        .selectOption(String(actors.teacher1.id));
      await delegatePage
        .getByLabel("Subject", { exact: true })
        .first()
        .fill(`Synthetic representation ${browserName}`);
      await delegatePage
        .getByLabel("Message", { exact: true })
        .first()
        .fill("Synthetic class representation, no private grade or message data.");
      await delegatePage.getByRole("button", { name: "Send", exact: true }).first().click();
      await delegatePage.waitForURL(/\/app\/school\/messages\/\d+/);
      const representationId = Number(new URL(delegatePage.url()).pathname.split("/").at(-1));
      assert.equal(
        (await call("teacher1", `/school/threads/${representationId}`)).thread.kind,
        "representation",
      );
      delegateChecks.push("delegate → teacher class representation");
      await delegatePage.goto(`${frontend}/app/school/messages?group=${group.id}`);
      const noticeSection = delegatePage
        .getByRole("heading", { name: "Audience announcement", exact: true })
        .locator("..");
      await noticeSection.getByRole("checkbox", { name: "Synthetic group 1", exact: true }).check();
      await noticeSection
        .getByLabel("Subject", { exact: true })
        .fill(`Synthetic delegate notice ${browserName}`);
      await noticeSection
        .getByLabel("Message", { exact: true })
        .fill("Synthetic class notice only.");
      await noticeSection.getByRole("button", { name: "Preview recipients", exact: true }).click();
      await noticeSection
        .getByRole("button", { name: "Publish announcement to this audience", exact: true })
        .click();
      await delegatePage
        .getByRole("dialog")
        .getByRole("button", { name: "Confirm", exact: true })
        .click();
      const publishedNotice = await until(
        async () =>
          (await call("student2", "/school/threads")).data.find(
            (t) => t.subject === `Synthetic delegate notice ${browserName}`,
          ),
        "Delegate announcement",
      );
      await call("empty", `/school/threads/${publishedNotice.id}`, "GET", undefined, 404);
      await call(
        "student1",
        "/school/notices/preview",
        "POST",
        { audience: "classes", group_ids: [groups[1].id] },
        403,
      );
      await call("student1", `/school/groups/${groups[1].id}/roster`, "GET", undefined, 403);
      await call("student1", `/school/assessments/${assessment.id}`, "GET", undefined, 403);
      assert.equal(
        Number(
          (
            await call(
              "student1",
              `/school/my-grades/${assessment.id}?student_id=${actors.student2.id}`,
            )
          ).score,
        ),
        15,
      );
      await call(
        "student1",
        "/school/years",
        "POST",
        { name: "Synthetic denied setup", starts_on: "2026-01-01", ends_on: "2028-12-31" },
        403,
      );
      await call(
        "student1",
        `/school/offerings/${offering.id}/assessments`,
        "POST",
        {
          title: "Synthetic denied assessment",
          type: "exam",
          assessed_on: "2026-10-08",
          maximum_score: 20,
          coefficient: 1,
        },
        403,
      );
      await call("student1", `/school/threads/${messageId}`, "GET", undefined, 404);
      delegateChecks.push(
        "authorized class-only notices",
        "other group/roster blocked",
        "peer grades/private threads blocked",
        "teacher/admin capabilities denied",
      );
      await call("admin", `/school/delegates/${currentDelegate.id}`, "DELETE", undefined, 204);
      await delegatePage.goto(`${frontend}/app/school`);
      await delegatePage
        .getByRole("heading", { name: "My school workspace", exact: true })
        .waitFor();
      assert.equal(
        await delegatePage.getByText("Your delegate responsibility", { exact: true }).count(),
        0,
      );
      await call(
        "student1",
        "/school/threads",
        "POST",
        {
          classroom_id: group.id,
          kind: "organization",
          subject: "Synthetic revoked request",
          body: "Synthetic revoked request",
          participant_ids: [actors.admin.id],
        },
        422, // The organization participant combination no longer contains an eligible delegate.
      );
      assert.ok(
        !(await call("admin", "/school/threads")).data.some(
          (t) => t.subject === "Synthetic revoked request",
        ),
      );
      await call("student1", `/school/threads/${delegateThread}`, "GET", undefined, 404);
      const replacement = await call(
        "admin",
        `/school/groups/${group.id}/delegates`,
        "POST",
        { student_id: actors.student2.id, ends_at: "2028-01-31T00:00:00Z" },
        201,
      );
      await call("student2", `/school/threads/${delegateThread}`, "GET", undefined, 404);
      await call("student2", `/school/threads/${representationId}`, "GET", undefined, 404);
      const { context: replacementContext, page: replacementPage } = await actorPage("student2");
      await replacementPage.goto(`${frontend}/app/school`);
      await replacementPage.getByText("Your delegate responsibility", { exact: true }).waitFor();
      await replacementContext.close();
      assert.equal(Number((await call("student1", "/school/my-grades")).data[0].score), 15);
      delegateChecks.push(
        "revocation removes badge and privileged access",
        "replacement has badge, never inherits private threads",
        "ordinary student results remain accessible",
      );
      await call("admin", `/school/delegates/${replacement.id}`, "DELETE", undefined, 204);
      currentDelegate = await call(
        "admin",
        `/school/groups/${group.id}/delegates`,
        "POST",
        { student_id: actors.student1.id, ends_at: "2028-01-31T00:00:00Z" },
        201,
      );
      await call(
        "admin",
        `/school/groups/${group.id}`,
        "PATCH",
        { delegate_notices_enabled: false },
        204,
      );
      recordJourney({
        browser: browserName,
        scenario: "B — delegate communication/notices, scoped privacy, revocation and replacement",
        result: "passed",
        checks: delegateChecks,
      });
      await delegateContext.close();
      writeFileSync(
        join(output, "review.json"),
        JSON.stringify({ phase, findings, screenshots, journeys }, null, 2),
      );
    }
    await browser.close();
    browser = null;
  }
  console.log(
    `UX audit ${phase}: ${findings.length} browser/role/locale route visits, ${findings.length * widths.length} base viewport checks plus all tabs, ${screenshots.length} screenshots, ${journeys.length} verified journey scenarios; ${revisited} visits executed in this verification run. Overflow visits: ${findings.filter((f) => f.overflows.length).length}. Browser error visits: ${findings.filter((f) => f.errors.length).length}.`,
  );
  if (phase === "after") {
    assert.equal(findings.filter((f) => f.overflows.length || f.errors.length).length, 0);
    assert.equal(journeys.length, routesOnly ? 0 : 12);
  }
  rmSync(join(output, "journey-failure.png"), { force: true });
} catch (error) {
  if (lastPage && !lastPage.isClosed())
    await lastPage
      .screenshot({ path: join(output, "journey-failure.png"), fullPage: true })
      .catch(() => {});
  throw error;
} finally {
  if (browser) await browser.close();
  for (const child of processes.reverse()) {
    if (process.platform === "win32")
      spawnSync("taskkill", ["/PID", String(child.pid), "/T", "/F"], { stdio: "ignore" });
    else child.kill("SIGTERM");
  }
  await new Promise((r) => setTimeout(r, 500));
  rmSync(workspace, { recursive: true, force: true });
}
