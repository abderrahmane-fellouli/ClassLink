// Run the existing browser suite against a fresh local demo DB, never backend/.env's DB.
import { spawn, spawnSync } from "node:child_process";
import { mkdtempSync, mkdirSync, writeFileSync, rmSync } from "node:fs";
import { join } from "node:path";
import { tmpdir } from "node:os";
import { randomBytes } from "node:crypto";
import { fileURLToPath } from "node:url";
import { createServer } from "node:net";
const root = fileURLToPath(new URL("../", import.meta.url));
const tempRoot = join(tmpdir(), "opencode");
mkdirSync(tempRoot, { recursive: true });
const workspace = mkdtempSync(join(tempRoot, "classlink-browser-"));
const database = join(workspace, "demo.sqlite");
writeFileSync(database, "");
const env = {
  ...process.env,
  APP_ENV: "local",
  APP_DEBUG: "false",
  APP_CONFIG_CACHE: join(workspace, "unused-config.php"),
  APP_KEY: randomBytes(32).toString("hex").slice(0, 32),
  APP_URL: "http://127.0.0.1:8000",
  FRONTEND_URL: "http://127.0.0.1:4173",
  DB_CONNECTION: "sqlite",
  DB_URL: "",
  DB_DATABASE: database,
  CACHE_STORE: "array",
  SESSION_DRIVER: "file",
  QUEUE_CONNECTION: "sync",
  FILESYSTEM_DISK: "local",
  MAIL_MAILER: "array",
  MAIL_URL: "",
  DEV_AUTH_ENABLED: "true",
  AI_PROVIDER_1_KEY: "",
  AI_PROVIDER_2_KEY: "",
  AI_PROVIDER_3_KEY: "",
  CLASSLINK_LOCAL_API: "http://127.0.0.1:8000",
};
let server;
try {
  for (const port of [8000, 4173])
    await new Promise((resolve, reject) => {
      const probe = createServer();
      probe.once("error", () =>
        reject(
          new Error(`Audit port ${port} is already occupied; refusing to reuse another service`),
        ),
      );
      probe.listen(port, "127.0.0.1", () => probe.close(resolve));
    });
  const seed = spawnSync("php", ["artisan", "migrate", "--seed", "--force", "--no-interaction"], {
    cwd: join(root, "backend"),
    env,
    encoding: "utf8",
  });
  if (seed.status !== 0) throw new Error("Disposable browser DB preparation failed");
  server = spawn("php", ["artisan", "serve", "--host=127.0.0.1", "--port=8000", "--no-reload"], {
    cwd: join(root, "backend"),
    env,
    stdio: "ignore",
  });
  let ready = false;
  for (let i = 0; i < 60; i++) {
    try {
      if ((await fetch("http://127.0.0.1:8000/up")).ok) {
        ready = true;
        break;
      }
    } catch {}
    await new Promise((r) => setTimeout(r, 250));
  }
  if (!ready) throw new Error("Disposable browser API unavailable");
  const suite = spawnSync(
    process.execPath,
    [
      join(root, "scripts/node_modules/@playwright/test/cli.js"),
      "test",
      "-c",
      "playwright.config.mjs",
    ],
    { cwd: join(root, "scripts"), env, stdio: "inherit", timeout: 600000 },
  );
  if (suite.status !== 0) throw new Error("Local browser gate failed");
  console.log("LOCAL_BROWSER_GATE_PASSED");
} finally {
  if (server) {
    if (process.platform === "win32")
      spawnSync("taskkill", ["/PID", String(server.pid), "/T", "/F"], { stdio: "ignore" });
    else server.kill("SIGTERM");
  }
  await new Promise((r) => setTimeout(r, 500));
  rmSync(workspace, { recursive: true, force: true });
}
