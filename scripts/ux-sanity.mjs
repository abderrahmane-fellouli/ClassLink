// Scan only intended Git changes; never open ignored .env files or production data.
import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { join } from "node:path";
const root = fileURLToPath(new URL("../", import.meta.url));
const git = (...args) =>
  execFileSync("git", args, {
    cwd: root,
    encoding: "utf8",
    stdio: ["ignore", "pipe", "ignore"],
  }).trim();
const files = [
  ...new Set(
    [
      ...git("diff", "--name-only").split("\n"),
      ...git("ls-files", "--others", "--exclude-standard").split("\n"),
    ].filter(Boolean),
  ),
];
const forbidden = files.filter((f) =>
  /(?:^|\/)\.env$|\.(?:sqlite(?:-wal|-shm|-journal)?|log|pem|key|png|jpg|csv|xlsx|zip)$|(?:^|\/)(?:dist|node_modules|artifacts|\.school-pilot-[^/]+)\//.test(
    f,
  ),
);
const secret =
  /-----BEGIN [A-Z ]*PRIVATE KEY-----|AKIA[0-9A-Z]{16}|gh[pousr]_[A-Za-z0-9]{30,}|xox[baprs]-[A-Za-z0-9-]{20,}|sk-(?:proj-)?[A-Za-z0-9_-]{30,}/;
const secrets = [],
  personal = [];
const cohortPattern = new RegExp(["DEVO", "WFS204"].join(""));
for (const file of files) {
  const content = readFileSync(join(root, file), "utf8");
  if (secret.test(content)) secrets.push(file);
  if (cohortPattern.test(content) || /(?:\+212|00212)[5-7]\d{8}|\b0[5-7]\d{8}\b/.test(content))
    personal.push(file);
}
console.log(
  `Intended files checked: ${files.length}; forbidden artifacts: ${forbidden.length}; credential-pattern files: ${secrets.length}; cohort/phone-pattern files: ${personal.length}`,
);
if (forbidden.length || secrets.length || personal.length) {
  console.error(
    "Review required for paths only:",
    [...forbidden, ...secrets, ...personal].join(", "),
  );
  process.exitCode = 1;
} else
  console.log(
    "UX_SANITY_PASSED — new fixtures reviewed as explicitly synthetic; actual ignored credentials were not read.",
  );
