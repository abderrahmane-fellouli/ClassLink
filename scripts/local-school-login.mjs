// Explicit loopback-only test login using the app's guarded dev authentication.
import { mkdirSync, writeFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { join } from "node:path";
const root = fileURLToPath(new URL("../", import.meta.url));
const email = process.argv.find((arg) => arg.startsWith("--email="))?.slice(8);
if (!email) throw new Error("Usage: node scripts/local-school-login.mjs --email=school-address");
const api = "http://127.0.0.1:8000/api";
const response = await fetch(`${api}/auth/dev/login`, {
  method: "POST",
  headers: { Accept: "application/json", "Content-Type": "application/json" },
  body: JSON.stringify({ email }),
});
if (!response.ok)
  throw new Error(
    `Local login refused (${response.status}). Start scripts/start-school-local.ps1 first. Remote/non-local DBs and production are rejected.`,
  );
const { token } = await response.json();
const folder = join(root, "artifacts/local-import");
mkdirSync(folder, { recursive: true });
const file = join(folder, "login.html");
const destination = `http://127.0.0.1:5173/auth/microsoft/callback#token=${encodeURIComponent(token)}`;
writeFileSync(
  file,
  `<!doctype html><meta charset="utf-8"><meta name="referrer" content="no-referrer"><title>ClassLink local login</title><a href="${destination}">Open the local ClassLink account</a>`,
  { mode: 0o600 },
);
console.log(
  `Local login link written to ${file}. Open it in your browser. Token was not printed. This is local test authentication, not Microsoft verification.`,
);
