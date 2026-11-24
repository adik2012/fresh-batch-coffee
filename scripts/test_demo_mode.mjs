import { readFileSync } from "node:fs";
import vm from "node:vm";

const path = process.argv[2] ?? "index.html";
const rawLines = readFileSync(path, "utf8").split("\n");
const lines = rawLines.map((line) => line.trim());

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

// Правило 3: в демо кнопки мессенджеров удаляются со страницы
const removal = lines.findIndex(
  (line) =>
    line.includes("querySelectorAll") &&
    line.includes("[data-telegram-order]") &&
    line.includes("[data-telegram-share]") &&
    line.includes("[data-whatsapp-order]")
);
check(
  "в демо кнопки мессенджеров удаляются со страницы (ветка else)",
  removal > 0 &&
    lines[removal - 1] === "} else {" &&
    lines[removal].endsWith(".forEach((element) => element.remove());")
);

// Правило 4: в демо функция отправки не вызывает fetch
const start = rawLines.findIndex((line) => line.includes("async function sendOrderToServer"));
let end = -1;
for (let i = start + 1; start >= 0 && i < rawLines.length; i += 1) {
  if (rawLines[i].trimEnd() === "      }") {
    end = i;
    break;
  }
}
const modeLine = lines.find((line) => line.startsWith("const ORDER_MODE = "));

let fetchCalls = 0;
let result = null;
let error = null;
if (start >= 0 && end > start) {
  const code = [modeLine ?? "", ...rawLines.slice(start, end + 1), "sendOrderToServer();"].join("\n");
  const sandbox = {
    window: { location: { protocol: "https:", hostname: "shop.example.com" } },
    leadForm: {
      elements: {
        website: { value: "" },
        name: { value: "Тест" },
        fulfillment: { value: "delivery" },
        payment: { value: "sbp" },
        "messenger-confirmation": { checked: true },
        "personal-data-consent": { checked: true },
      },
    },
    phoneInput: { value: "+7 (900) 000-00-00" },
    addressInput: { value: "ул. Тестовая, 1" },
    promoCodeActive: false,
    cart: [{ id: "ethiopia-irghacheff", quantity: 1, grind: "" }],
    document: { querySelector: () => ({ value: "" }) },
    fetch: async () => {
      fetchCalls += 1;
      return { ok: true, json: async () => ({ ok: true }) };
    },
  };
  try {
    result = await vm.runInContext(code, vm.createContext(sandbox));
  } catch (e) {
    error = e;
  }
}
check(
  "в демо функция отправки не вызывает fetch и возвращает demo: true",
  start >= 0 && end > start && error === null && fetchCalls === 0 && result?.demo === true
);
console.log(
  `  детали: вызовов fetch = ${fetchCalls}, ошибка = ${error ? error.message : "нет"}, demo = ${result?.demo}`
);

process.exit(failed === 0 ? 0 : 1);