import { spawn } from "node:child_process";
import { mkdtempSync, readFileSync, writeFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";

const path = process.argv[2] ?? "send.php";
let failed = 0;
function check(name, ok) {
  console.log(ok ? `ОК: ${name}` : `ПРОВАЛ: ${name}`);
  if (!ok) failed += 1;
}

if (typeof fetch !== "function") {
  console.log("Нужен Node.js 18 или новее (нет встроенного fetch).");
  process.exit(1);
}

// Подкладываем заведомую ошибку сразу после заголовков, в копию файла.
const anchor = "header('X-Content-Type-Options: nosniff');";
const source = readFileSync(path, "utf8");
if (!source.includes(anchor)) {
  console.log("ПРОВАЛ: в send.php не найдена строка-ориентир для проверки");
  process.exit(1);
}
const dir = mkdtempSync(join(tmpdir(), "sendphp-"));
writeFileSync(join(dir, "send.php"), source.replace(anchor, `${anchor}\necho $leak_probe_undefined;`));

// Запускаем встроенный сервер PHP с ВКЛЮЧЁННЫМ показом ошибок (плохой хостинг).
const port = 18000 + Math.floor(Math.random() * 1000);
const server = spawn(
  "php",
  ["-d", "display_errors=1", "-d", "log_errors=1", "-S", `127.0.0.1:${port}`, "-t", dir],
  { stdio: ["ignore", "ignore", "pipe"] }
);
let log = "";
server.stderr.on("data", (chunk) => {
  log += chunk.toString();
});

// Ждём, пока сервер начнёт отвечать, и делаем один запрос.
let body = null;
for (let i = 0; i < 30 && body === null; i += 1) {
  try {
    body = await (await fetch(`http://127.0.0.1:${port}/send.php`)).text();
  } catch {
    await new Promise((resolve) => setTimeout(resolve, 100));
  }
}
await new Promise((resolve) => {
  server.once("close", resolve);
  server.kill();
});
rmSync(dir, { recursive: true, force: true });

check("сервер ответил", body !== null);
check(
  "текст ошибки PHP не попал в ответ посетителю",
  body !== null && !/Warning|Notice|Fatal|on line/i.test(body) && !body.includes(dir)
);
check("ошибка действительно произошла и записана в журнал сервера", log.includes("leak_probe_undefined"));
console.log(`  детали: ответ = ${JSON.stringify(body?.slice(0, 100))}`);

process.exit(failed === 0 ? 0 : 1);