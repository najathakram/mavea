// Slice the canvas doc into per-section files + a screen index.
// Code before models: agents read a 10-30KB slice, never the 274KB whole.

import { readFileSync, writeFileSync, mkdirSync } from "node:fs";
import { join } from "node:path";

const SRC = process.argv[2];
const OUTDIR = process.argv[3];
mkdirSync(OUTDIR, { recursive: true });

const src = readFileSync(SRC, "utf8").split("\n");

// Section boundaries
const secs = [];
src.forEach((line, i) => {
  const m = line.match(/<section class="sec" id="([^"]+)"/);
  if (m) secs.push({ id: m[1], start: i });
});
secs.forEach((s, i) => {
  s.end = i + 1 < secs.length ? secs[i + 1].start - 1 : src.length - 1;
});

// The <helmet> block holds the canvas-only chrome; keep it separately so no
// agent mistakes canvas scaffolding (.sec/.fr/.scr) for site CSS.
const helmetStart = src.findIndex((l) => l.includes("<helmet"));
const helmetEnd = src.findIndex((l) => l.includes("</helmet>"));
writeFileSync(
  join(OUTDIR, "00-canvas-chrome.html"),
  src.slice(helmetStart, helmetEnd + 1).join("\n"),
  "utf8"
);

const index = [];
for (const s of secs) {
  const body = src.slice(s.start, s.end + 1);
  const file = `${String(secs.indexOf(s) + 1).padStart(2, "0")}-${s.id}.html`;
  writeFileSync(join(OUTDIR, file), body.join("\n"), "utf8");

  const screens = [];
  body.forEach((line, i) => {
    const m = line.match(/data-screen-label="([^"]+)"/);
    if (m) screens.push({ label: m[1], line: s.start + i + 1 });
  });
  index.push({
    section: s.id,
    file,
    lines: `${s.start + 1}-${s.end + 1}`,
    bytes: body.join("\n").length,
    screens,
  });
}

writeFileSync(join(OUTDIR, "index.json"), JSON.stringify(index, null, 2), "utf8");

for (const s of index) {
  console.log(
    `${s.file.padEnd(24)} ${String(Math.round(s.bytes / 1024) + "KB").padStart(6)}  ${s.screens.length} screens: ${s.screens.map((x) => x.label).join(", ")}`
  );
}
