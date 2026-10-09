// Local integration checks: the real roster is loaded only from a private file.
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { execFileSync } from "node:child_process";
import { fileURLToPath } from "node:url";
import { chromium } from "@playwright/test";
const input = JSON.parse(readFileSync(process.argv[2], "utf8"));
const adminEmail = process.argv[3];
if (!adminEmail)
  throw new Error("Usage: node scripts/verify-school-local.mjs private-roster.json admin-email");
const api = "http://127.0.0.1:8000/api";
const sessions = [];
let checks = 0;
async function request(path, token, status = 200, method = "GET", body) {
  const response = await fetch(`${api}${path}`, {
    method,
    headers: {
      Accept: "application/json",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...(body ? { "Content-Type": "application/json" } : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
  });
  assert.equal(response.status, status, `${method} ${path}: unexpected response`);
  checks++;
  return response.status === 204 ? null : response.json();
}
async function login(email, role) {
  const session = await request("/auth/dev/login", null, 200, "POST", { email });
  assert.equal(session.user.role, role);
  sessions.push(session);
  return session;
}
const browser = await chromium.launch({ headless: true });
try {
  const student = await login(input.students[0].email, "student");
  const teacher = await login(input.teachers[0].email.toLowerCase(), "teacher");
  const admin = await login(adminEmail, "admin");
  const studentSchool = await request("/school", student.token);
  const group = studentSchool.groups.find((g) => g.official_code === input.group_code);
  assert.ok(group);
  assert.equal(group.is_read_only, false);
  assert.equal(studentSchool.offerings.length, input.teachers.length);
  assert.equal(studentSchool.delegates.length, 0);
  const roster = await request(`/school/groups/${group.id}/roster`, student.token);
  assert.equal(roster.total, input.students.length);
  assert.ok(roster.data.every((row) => !("email" in row) && !("school_identifier" in row)));
  const teacherSchool = await request("/school", teacher.token);
  assert.equal(teacherSchool.offerings.length, 1);
  assert.equal(teacherSchool.offerings[0].module_code, input.teachers[0].module_code);
  const own = teacherSchool.offerings[0];
  const other = studentSchool.offerings.find((o) => o.id !== own.id);
  await request(`/school/offerings/${own.id}/tools/materials`, teacher.token);
  await request(`/school/offerings/${other.id}/tools/materials`, teacher.token, 403);
  await request(`/school/groups/${group.id}/roster`, teacher.token);
  await request(`/school/groups/${group.id}/requests`, teacher.token, 403);
  await request(`/school/offerings/${other.id}/teachers`, teacher.token, 403, "POST", {
    teacher_id: teacher.user.id,
  });
  await request(`/school/groups/${group.id}/delegates`, student.token, 403, "POST", {
    student_id: student.user.id,
    ends_at: "2099-01-01",
  });
  await request("/admin/users", student.token, 403);
  await request("/admin/users", teacher.token, 403);
  const directory = await request("/admin/users", admin.token);
  assert.equal(
    directory.meta?.total ?? directory.total,
    input.students.length + input.teachers.length + 2,
  );
  await request("/school/my-grades", student.token);
  await request("/attempts/1", student.token, 403);
  for (const session of [student, teacher, admin]) {
    const context = await browser.newContext();
    const page = await context.newPage();
    const errors = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await page.goto(
      `http://127.0.0.1:5173/auth/microsoft/callback#token=${encodeURIComponent(session.token)}`,
    );
    await page.waitForURL(/\/app(?:\/|$)/, { timeout: 30000 });
    await page.goto("http://127.0.0.1:5173/app/school");
    await page
      .getByText(input.group_code, { exact: false })
      .filter({ visible: true })
      .first()
      .waitFor({ timeout: 30000 });
    const text = await page.locator("body").innerText();
    assert.ok(text.includes(input.group_code));
    if (session.user.role === "student") {
      assert.ok(text.includes(input.teachers[0].module_title));
      for (const peer of input.students.slice(1)) assert.ok(!text.includes(peer.email));
    }
    assert.deepEqual(errors, [], "No browser runtime errors");
    assert.ok(!page.url().includes("token="), "Callback token removed from browser URL");
    checks++;
    await context.close();
  }
  execFileSync(
    process.execPath,
    [
      fileURLToPath(new URL("./local-school-login.mjs", import.meta.url)),
      `--email=${input.students[0].email}`,
    ],
    { stdio: "pipe" },
  );
  const helperFile = new URL("../artifacts/local-import/login.html", import.meta.url);
  const helperLink = readFileSync(helperFile, "utf8").match(/href="([^"]+)"/)[1];
  const helperToken = new URLSearchParams(new URL(helperLink).hash.slice(1)).get("token");
  sessions.push({ token: helperToken });
  const context = await browser.newContext();
  const page = await context.newPage();
  await page.goto(helperFile.href);
  await page.getByRole("link").click();
  await page.waitForURL(/\/app(?:\/|$)/, { timeout: 30000 });
  await context.close();
  checks++;
  console.log(
    `PASS local API/role/privacy/browser checks (${checks}); representative student, teacher and admin logins successful. Credentials not printed.`,
  );
} finally {
  for (const session of sessions)
    await request("/auth/logout", session.token, 204, "POST").catch(() => {});
  await browser.close();
}
