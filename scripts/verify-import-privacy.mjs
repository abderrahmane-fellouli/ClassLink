import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
const input = JSON.parse(readFileSync(process.argv[2], "utf8"));
const identities = [...input.students, ...input.teachers]
  .flatMap((row) => [row.name, row.email])
  .map((value) => value.toLowerCase());
const git = (...args) => execFileSync("git", args, { encoding: "utf8" });
const changed = new Set(
  [
    ...git("diff", "--name-only").trim().split("\n"),
    ...git("diff", "--cached", "--name-only").trim().split("\n"),
    ...git("ls-files", "--others", "--exclude-standard").trim().split("\n"),
  ].filter(Boolean),
);
for (const path of changed) {
  const text = readFileSync(path, "utf8").toLowerCase();
  assert.ok(
    !identities.some((value) => text.includes(value)),
    `Personal roster data found in changed source: ${path}`,
  );
}
for (const path of [
  "artifacts/local-import/roster.json",
  "artifacts/local-import/first-report.json",
  "artifacts/local-import/rerun-report.json",
  "artifacts/local-import/login.html",
  "artifacts/local-import/backups/sample.sqlite",
  "artifacts/local-import/tokens.json",
  "backend/database/database.sqlite",
  "backend/.env",
]) {
  assert.equal(git("check-ignore", path).trim(), path, "Private artifacts must be ignored");
}
console.log(
  `PASS privacy scan: ${changed.size} changed source files contain no supplied real names or emails; private input/results/login files ignored.`,
);
