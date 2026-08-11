// Reassemble the Claude Design canvas doc from the chunked MCP reads.
//
// Each chunk file is a tool-result wrapper:
//   <untrusted-project-content ... lines="A-B" total_lines="N">
//   ...entity-escaped body...
//   </untrusted-project-content>
//   (trailing note)
//
// Chunks overlap, so we place lines by absolute line number and assert full
// coverage before writing. Entity decoding is last (&amp; must go last or it
// would double-decode a literal "&amp;lt;" in the source).

import { readFileSync, writeFileSync, readdirSync } from "node:fs";
import { join } from "node:path";

const DIR = process.argv[2];
const OUT = process.argv[3];
const PATCH = process.argv[4]; // optional: extra chunk file written by hand

const files = readdirSync(DIR)
  .filter((f) => f.startsWith("mcp-claude_design-read_file-") && f.endsWith(".txt"))
  .map((f) => join(DIR, f));
if (PATCH) files.push(PATCH);

const lines = new Map();
let total = null;

for (const file of files) {
  const raw = readFileSync(file, "utf8");
  const open = raw.match(
    /<untrusted-project-content[^>]*\slines="(\d+)-(\d+)"[^>]*\stotal_lines="(\d+)"[^>]*>\n/
  );
  if (!open) {
    console.error(`skip (no wrapper): ${file}`);
    continue;
  }
  const start = Number(open[1]);
  const end = Number(open[2]);
  total ??= Number(open[3]);

  const bodyStart = open.index + open[0].length;
  const bodyEnd = raw.lastIndexOf("</untrusted-project-content>");
  // The wrapper is emitted as body + "\n" + closing tag, so drop that one newline.
  const body = raw.slice(bodyStart, bodyEnd).replace(/\n$/, "");
  const got = body.split("\n");

  // The wrapper emits one trailing blank line beyond the declared range. Left
  // in place it lands on line end+1 and, depending on file order, overwrites a
  // real line from another chunk with "". Trust the header, not the body length.
  const expected = end - start + 1;
  if (got.length < expected) {
    console.error(`FAIL ${file}: header says ${expected} lines, body has only ${got.length}`);
    process.exit(1);
  }
  if (got.length > expected) got.length = expected;
  got.forEach((text, i) => lines.set(start + i, text));
}

const missing = [];
for (let n = 1; n <= total; n++) if (!lines.has(n)) missing.push(n);
if (missing.length) {
  console.error(`FAIL: ${missing.length} line(s) missing, first: ${missing.slice(0, 12)}`);
  process.exit(1);
}

const decode = (s) =>
  s.replace(/&lt;/g, "<").replace(/&gt;/g, ">").replace(/&amp;/g, "&");

const out = [];
for (let n = 1; n <= total; n++) out.push(decode(lines.get(n)));

writeFileSync(OUT, out.join("\n") + "\n", "utf8");
console.log(`OK: ${total} lines -> ${OUT}`);
