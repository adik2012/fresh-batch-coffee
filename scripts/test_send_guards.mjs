import { spawn } from "node:child_process";
import { mkdtempSync, readFileSync, writeFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";

const args = process.argv.slice(2);
const path = args.find((arg) => !arg.startsWith("--")) ?? "send.php";
const breakName = args.find((arg) => arg.startsWith("--break="))?.slice("--break=".length);

// Намеренные поломки защит: [что искать, на что заменить].
const mutations = {
  method: ["!== 'POST'", "=== 'NEVER'"],
  type: ["$contentType !== 'application/json'", "$contentType === 'NEVER'"],
  size: ["32768", "99999999"],
};

let failed = 0;
function check(name, ok) {
  console.log(ok ? `ОК: ${name}` : `ПРОВАЛ: ${name}`);
  if (!ok) failed += 1;
}

if (typeof fetch !== "function") {
  console.log("Нужен Node.js 18 или новее (нет встроенного fetch).");
  process.exit(1);
}

// Копия send.php во временной папке. Файла config.php там нет, поэтому
// даже при ошибке теста ничего не уйдёт в Telegram.
let source = readFileSync(path, "utf8");
if (breakName) {
  const mutation = mutations[breakName];
  if (!mutation || !source.includes(mutation[0])) {
    console.log(`ПРОВАЛ: нечего ломать для "${breakName}" (нет такой защиты в send.php)`);
    process.exit(1);
  }
  source = source.split(mutation[0]).join(mutation[1]);
}
const dir = mkdtempSync(join(tmpdir(), "sendguards-"));
writeFileSync(join(dir, "send.php"), source);

const port = 19000 + Math.floor(Math.random() * 1000);
const server = spawn("php", ["-S", `127.0.0.1:${port}`, "-t", dir], { stdio: "ignore" });
const url = `http://127.0.0.1:${port}/send.php`;

// Отправляет запрос; пока сервер запускается, повторяет попытку до трёх секунд.
async function send(options = {}) {
  for (let i = 0; i < 30; i += 1) {
    try {
      const response = await fetch(url, options);
      return { status: response.status, text: await response.text() };
    } catch {
      await new Promise((resolve) => setTimeout(resolve, 100));
    }
  }
  return null;
}

function postJson(data) {
  return send({
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(data),
  });
}

const get = await send();
check("GET вместо POST отклоняется (405)", get?.status === 405);

const plain = await send({
  method: "POST",
  headers: { "Content-Type": "text/plain" },
  body: "{}",
});
check("POST не в формате JSON отклоняется (415)", plain?.status === 415);

const big = await postJson({ name: "x".repeat(40000) });
check("слишком большой запрос отклоняется (413)", big?.status === 413);

await new Promise((resolve) => {
  server.once("close", resolve);
  server.kill();
});
rmSync(dir, { recursive: true, force: true });

console.log(
  `  детали: GET → ${get?.status}, text/plain → ${plain?.status}, большой → ${big?.status}` +
    (breakName ? ` (намеренно сломана защита: ${breakName})` : "")
);
process.exitCode = failed === 0 ? 0 : 1;