// Read-only, privacy-preserving summary of the local UX evidence.
import { readFileSync, existsSync } from "node:fs";
import { fileURLToPath } from "node:url";
const root = fileURLToPath(new URL("../", import.meta.url));
const source = readFileSync(`${root}/frontend/src/router.tsx`, "utf8");
const routes = [...source.matchAll(/\bpath:\s*['"]([^'"]+)['"]/g)].map((m) => m[1]);
console.log(`Route definitions: ${routes.length}`);
for (const route of routes) console.log(`ROUTE ${route}`);
for (const phase of ["before", "after"]) {
  const report = JSON.parse(readFileSync(`${root}/artifacts/ux/${phase}/review.json`, "utf8"));
  const widths = phase === "before" ? 3 : 10;
  console.log(
    `${phase}: visits=${report.findings.length}; baseViewportChecks=${report.findings.length * widths}; tabStates=${report.findings.reduce((sum, row) => sum + row.tabs.length, 0)}; screenshots=${report.screenshots.length}; journeys=${report.journeys?.length ?? 0}`,
  );
  for (const row of report.findings.filter((r) => r.overflows.length || r.errors.length))
    console.log(
      `ISSUE ${row.browser} ${row.actor} ${row.route}: overflows=${JSON.stringify(row.overflows)} errors=${JSON.stringify(row.errors)}`,
    );
  for (const row of report.journeys ?? [])
    console.log(`JOURNEY ${row.browser}: ${row.scenario}: ${row.result}`);
}
const journeyFile = `${root}/artifacts/ux/journeys/review.json`;
if (existsSync(journeyFile)) {
  const report = JSON.parse(readFileSync(journeyFile, "utf8"));
  console.log(
    `Separate committed-state journey scenarios: ${report.journeys.length}; recovery/delegate checks: ${report.journeys.reduce((sum, row) => sum + (row.checks?.length ?? 0), 0)}`,
  );
  for (const row of report.journeys)
    console.log(`JOURNEY ${row.browser}: ${row.scenario}: ${row.result}`);
}
