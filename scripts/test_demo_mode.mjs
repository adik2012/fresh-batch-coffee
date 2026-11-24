import { readFileSync } from "node:fs";

const path = process.argv[2] ?? "index.html";
const lines = readFileSync(path, "utf8")
  .split("\n")
  .map((line) => line.trim());

let failed = 0;
function check(name, ok) {
  console.log(ok ? `ОК: ${name}` : `ПРОВАЛ: ${name}`);
  if (!ok) failed += 1;
}

// Правило 1: по умолчанию демо-режим
check(
  "по умолчанию демо-режим",
  lines.some((line) => line.startsWith('const ORDER_MODE = "demo"'))
);

// Правило 2: адреса мессенджеров выдаются только в боевом режиме
const first = lines.findIndex((line) => line.includes('[data-telegram-order]").href'));
const guarded =
  first > 0 &&
  lines[first - 1] === 'if (ORDER_MODE === "live") {' &&
  lines[first + 1].includes('[data-telegram-share]").href') &&
  lines[first + 2].includes('[data-whatsapp-order]").href') &&
  lines[first + 3] === "}";
check('адреса мессенджеров выдаются только внутри if (ORDER_MODE === "live")', guarded);

process.exit(failed === 0 ? 0 : 1);