<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cleanText(string $value, int $limit): string
{
    $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? '';
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

    return mb_substr($value, 0, $limit);
}

function htmlText(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, ['ok' => false, 'message' => 'Method not allowed.']);
}

$contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
if ($contentType !== 'application/json') {
    respond(415, ['ok' => false, 'message' => 'Expected application/json.']);
}

if (isset($_SERVER['CONTENT_LENGTH']) && (int) $_SERVER['CONTENT_LENGTH'] > 32768) {
    respond(413, ['ok' => false, 'message' => 'Request is too large.']);
}

$rawBody = file_get_contents('php://input', false, null, 0, 32769);
if ($rawBody === false || strlen($rawBody) > 32768) {
    respond(413, ['ok' => false, 'message' => 'Request is too large.']);
}

try {
    $payload = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
} catch (JsonException $error) {
    respond(400, ['ok' => false, 'message' => 'Invalid JSON request.']);
}

if (!is_array($payload)) {
    respond(400, ['ok' => false, 'message' => 'Invalid order.']);
}

$honeypot = $payload['website'] ?? '';
if (!is_string($honeypot)) {
    respond(400, ['ok' => false, 'message' => 'Invalid order.']);
}
$honeypot = cleanText($honeypot, 200);
if ($honeypot !== '') {
    respond(400, ['ok' => false, 'message' => 'Invalid order.']);
}

$nameInput = $payload['name'] ?? null;
$phoneInput = $payload['phone'] ?? null;
$fulfillment = $payload['fulfillment'] ?? null;
$addressInput = $payload['address'] ?? '';
$payment = $payload['payment'] ?? null;
$items = $payload['items'] ?? null;
$messengerConfirmation = $payload['messenger_confirmation'] ?? null;
$personalDataConsent = $payload['personal_data_consent'] ?? null;
$promoCode = $payload['promo_code'] ?? '';

if (
    !is_string($nameInput)
    || !is_string($phoneInput)
    || !in_array($fulfillment, ['delivery', 'pickup'], true)
    || !is_string($addressInput)
    || !in_array($payment, ['sbp', 'on-receipt'], true)
    || !is_bool($messengerConfirmation)
    || $personalDataConsent !== true
    || !is_string($promoCode)
    || !is_array($items)
    || count($items) < 1
    || count($items) > 20
) {
    respond(422, ['ok' => false, 'message' => 'Order details are incomplete or invalid.']);
}

$addressText = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $addressInput) ?? '';
$addressText = trim(preg_replace('/\s+/u', ' ', $addressText) ?? '');
$addressLength = mb_strlen($addressText);
if (
    $fulfillment === 'delivery'
    && ($addressLength < 5 || $addressLength > 200)
) {
    respond(422, ['ok' => false, 'message' => 'Please provide a valid delivery address.']);
}

$promoCode = cleanText($promoCode, 32);
$name = cleanText($nameInput, 80);
if ($name === '') {
    respond(422, ['ok' => false, 'message' => 'Please provide your name.']);
}

$phoneDigits = preg_replace('/\D+/', '', strip_tags($phoneInput)) ?? '';
if (strlen($phoneDigits) === 11 && ($phoneDigits[0] === '7' || $phoneDigits[0] === '8')) {
    $phoneDigits = substr($phoneDigits, 1);
}
if (strlen($phoneDigits) !== 10) {
    respond(422, ['ok' => false, 'message' => 'Please provide a valid phone number.']);
}
$phone = '+7 (' . substr($phoneDigits, 0, 3) . ') '
    . substr($phoneDigits, 3, 3) . '-'
    . substr($phoneDigits, 6, 2) . '-'
    . substr($phoneDigits, 8, 2);

$catalog = [
    'ethiopia-irghacheff' => ['name' => 'Эфиопия Иргачефф', 'price' => 750],
    'brazil-serrado' => ['name' => 'Бразилия Серрадо', 'price' => 590],
    'drip-set' => ['name' => 'Дрип-сет «Неделя без кофейни»', 'price' => 850],
    'starter-set' => ['name' => 'Стартовый набор «Три страны»', 'price' => 1190],
    'strawberry-basil-cheesecake' => ['name' => 'Базиликовый чизкейк с клубникой', 'price' => 380],
    'gift-box' => ['name' => 'Подарочный бокс «Партия на подарок»', 'price' => 2490],
    'drip-sample' => ['name' => 'Пробник дрипа', 'price' => 0],
];
$allowedGrinds = ['', 'В зёрнах', 'Под фильтр', 'Под эспрессо', 'Под турку'];
$orderItemsByKey = [];
$subtotal = 0;
$paidSubtotal = 0;
$sampleQty = 0;

foreach ($items as $item) {
    if (!is_array($item)) {
        respond(422, ['ok' => false, 'message' => 'Invalid order item.']);
    }

    $productId = $item['id'] ?? null;
    $quantity = $item['quantity'] ?? null;
    $grind = $item['grind'] ?? '';
    if (
        !is_string($productId)
        || !isset($catalog[$productId])
        || !is_int($quantity)
        || $quantity < 1
        || $quantity > 50
        || !is_string($grind)
        || !in_array($grind, $allowedGrinds, true)
    ) {
        respond(422, ['ok' => false, 'message' => 'Invalid order item.']);
    }

    $product = $catalog[$productId];
    $lineTotal = $product['price'] * $quantity;
    $itemKey = $productId . "\0" . $grind;
    if (isset($orderItemsByKey[$itemKey])) {
        if ($orderItemsByKey[$itemKey]['quantity'] + $quantity > 50) {
            respond(422, ['ok' => false, 'message' => 'Invalid order.']);
        }
        $orderItemsByKey[$itemKey]['quantity'] += $quantity;
        $orderItemsByKey[$itemKey]['line_total'] += $lineTotal;
    } else {
        $orderItemsByKey[$itemKey] = [
            'name' => $product['name'],
            'quantity' => $quantity,
            'grind' => $grind === '' ? '' : cleanText($grind, 32),
            'line_total' => $lineTotal,
        ];
    }
    $subtotal += $lineTotal;
    if ($product['price'] > 0) {
        $paidSubtotal += $lineTotal;
    }
    if ($productId === 'drip-sample') {
        $sampleQty += $quantity;
    }
}

if ($paidSubtotal <= 0 || $sampleQty > 1) {
    respond(422, ['ok' => false, 'message' => 'Invalid order.']);
}
$orderItems = array_values($orderItemsByKey);

if ($promoCode !== '' && strtoupper(trim($promoCode)) !== 'DRIPO15') {
    respond(422, ['ok' => false, 'message' => 'Invalid promo code.']);
}

$configPath = __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
if (!is_file($configPath)) {
    respond(503, ['ok' => false, 'message' => 'Order notifications are not configured.']);
}

$config = require $configPath;
if (!is_array($config)) {
    respond(503, ['ok' => false, 'message' => 'Order notifications are not configured.']);
}

$botToken = $config['TELEGRAM_BOT_TOKEN'] ?? '';
$chatId = $config['TELEGRAM_CHAT_ID'] ?? '';
if (
    !is_string($botToken)
    || !preg_match('/^\d+:[A-Za-z0-9_-]+$/', $botToken)
    || !is_string($chatId)
    || !preg_match('/^-?\d+$/', $chatId)
) {
    respond(503, ['ok' => false, 'message' => 'Order notifications are not configured.']);
}

$pickupDiscount = $fulfillment === 'pickup' ? (int) round($subtotal * 0.10) : 0;
$discountedSubtotal = $subtotal - $pickupDiscount;
$promoDiscount = strtoupper(trim($promoCode)) === 'DRIPO15'
    ? (int) round($discountedSubtotal * 0.15)
    : 0;
$total = $discountedSubtotal - $promoDiscount;

$itemLines = [];
foreach ($orderItems as $item) {
    $line = '• ' . htmlText($item['name']);
    if ($item['grind'] !== '') {
        $line .= ', помол ' . htmlText($item['grind']);
    }
    $itemLines[] = $line . ' — ' . $item['quantity'] . ' шт. — '
        . number_format($item['line_total'], 0, ',', ' ') . ' ₽';
}

$message = implode("\n", [
    '<b>Новый заказ «Свежая Партия»</b>',
    'Клиент: ' . htmlText($name),
    'Телефон: ' . htmlText($phone),
    '',
    '<b>Состав заказа:</b>',
    ...$itemLines,
    $fulfillment === 'pickup'
        ? 'Адрес кофейни: ' . htmlText('г. Москва, ул. Покровка, 24, стр. 2')
        : 'Адрес доставки: ' . htmlText($addressText),
    'Оплата: ' . ($payment === 'sbp' ? 'СБП (не оплачено)' : 'При получении'),
    'Подтверждение: ' . ($messengerConfirmation ? 'написать в мессенджер' : 'позвонить'),
    'Сумма товаров: ' . number_format($subtotal, 0, ',', ' ') . ' ₽',
    'Скидка самовывоза: ' . number_format($pickupDiscount, 0, ',', ' ') . ' ₽',
    'Скидка DRIPO15: ' . number_format($promoDiscount, 0, ',', ' ') . ' ₽',
    '<b>Итого: ' . number_format($total, 0, ',', ' ') . ' ₽</b>',
]);

$remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
if (filter_var($remoteAddress, FILTER_VALIDATE_IP) === false) {
    respond(400, ['ok' => false, 'message' => 'Unable to validate request source.']);
}

$rateLimitPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR
    . 'fresh-batch-order-'
    . hash('sha256', $remoteAddress)
    . '.lock';
$rateLimitFile = fopen($rateLimitPath, 'c+');
if ($rateLimitFile === false || !flock($rateLimitFile, LOCK_EX)) {
    respond(500, ['ok' => false, 'message' => 'Unable to process order right now.']);
}

$lastRequest = (int) stream_get_contents($rateLimitFile);
$now = time();
if ($lastRequest > 0 && $now - $lastRequest < 30) {
    flock($rateLimitFile, LOCK_UN);
    fclose($rateLimitFile);
    header('Retry-After: ' . max(1, 30 - ($now - $lastRequest)));
    respond(429, ['ok' => false, 'message' => 'Please wait before placing another order.']);
}

rewind($rateLimitFile);
$saved = ftruncate($rateLimitFile, 0)
    && fwrite($rateLimitFile, (string) $now) !== false
    && fflush($rateLimitFile);
flock($rateLimitFile, LOCK_UN);
fclose($rateLimitFile);
if (!$saved) {
    respond(500, ['ok' => false, 'message' => 'Unable to process order right now.']);
}

if (!function_exists('curl_init')) {
    respond(500, ['ok' => false, 'message' => 'Telegram transport is not available.']);
}

$curl = curl_init('https://api.telegram.org/bot' . $botToken . '/sendMessage');
if ($curl === false) {
    respond(500, ['ok' => false, 'message' => 'Unable to prepare order notification.']);
}

curl_setopt_array($curl, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => 'true',
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);
$telegramResponse = curl_exec($curl);
$telegramStatus = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
$curlError = curl_error($curl);
curl_close($curl);

if ($telegramResponse === false || $telegramStatus !== 200) {
    error_log('Telegram order delivery failed: ' . ($curlError !== '' ? $curlError : 'HTTP ' . $telegramStatus));
    respond(502, ['ok' => false, 'message' => 'Order notification could not be delivered.']);
}

$telegramResult = json_decode($telegramResponse, true);
if (!is_array($telegramResult) || ($telegramResult['ok'] ?? false) !== true) {
    error_log('Telegram order delivery failed: API returned an unsuccessful response.');
    respond(502, ['ok' => false, 'message' => 'Order notification could not be delivered.']);
}

respond(200, ['ok' => true]);
