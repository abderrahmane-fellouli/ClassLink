import { chromium } from "@playwright/test";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { join } from "node:path";
const root = fileURLToPath(new URL("../", import.meta.url));
const folder = join(root, "artifacts/ux/after");
const report = JSON.parse(readFileSync(join(folder, "review.json"), "utf8"));
const names = [
  ...new Set(
    report.screenshots.filter((name) => !name.startsWith("firefox-") && name.endsWith("-320.png")),
  ),
];
const browser = await chromium.launch();
try {
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  for (let start = 0; start < names.length; start += 12) {
    const batch = names.slice(start, start + 12);
    const html = `<html><head><style>body{font:14px sans-serif;background:#e8edf3;padding:16px}main{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}section{background:white;padding:8px}h2{font-size:14px;margin:0 0 8px}img{width:100%;height:460px;object-fit:cover;object-position:top}</style></head><body><main>${batch.map((name) => `<section><h2>${name}</h2><img src="data:image/png;base64,${readFileSync(join(folder, name)).toString("base64")}"></section>`).join("")}</main></body></html>`;
    await page.setContent(html);
    await page.evaluate(() => Promise.all([...document.images].map((img) => img.decode())));
    await page.screenshot({
      path: join(folder, `contact-sheet-${start / 12 + 1}.png`),
      fullPage: true,
    });
  }
  console.log(
    `Visual contact sheets: ${Math.ceil(names.length / 12)}; mobile route images represented: ${names.length}`,
  );
} finally {
  await browser.close();
}
