<?php

// ========================
//  НАСТРОЙКИ
// ========================
$TELEGRAM_BOT_TOKEN = "8235183293:AAFjAhCwp1Y7OD21MLp8YUTSavMyf45y4Q4";
$TELEGRAM_CHAT_ID   = "-5206806235";

// Входящий вебхук Bitrix24 (созданный в разделе Приложения → Вебхуки)
$B24_WEBHOOK = "https://tugur.bitrix24.ru/rest/15/c9x0qjz9quea1o01/";

// Ваши ID пользовательских полей в сделке
$UF_POSITIVE_EVENT = "UF_CRM_1768751320643";   // Положительные события
$UF_NEGATIVE_EVENT = "UF_CRM_1768751944908";   // Отрицательные события
$UF_EVENT_DATE     = "UF_CRM_1770607841259";       // Дата изменения события

// ========================
//  ПОЛУЧАЕМ ID СДЕЛКИ ИЗ ВЕБХУКА
// ========================
$input = file_get_contents("php://input");
$data = json_decode($input, true);

$dealId = $data["data"]["FIELDS"]["ID"];

if (!$dealId) {
    exit("No deal ID");
}

// ========================
//  1. ПОЛУЧАЕМ ДАННЫЕ СДЕЛКИ
// ========================
$deal = json_decode(file_get_contents($B24_WEBHOOK . "crm.deal.get.json?ID=" . $dealId), true);
$deal = $deal["result"];

// ========================
//  2. ПОЛУЧАЕМ ДАННЫЕ КОМПАНИИ
// ========================
$company = [];
if (!empty($deal["COMPANY_ID"])) {
    $company = json_decode(file_get_contents($B24_WEBHOOK . "crm.company.get.json?ID=" . $deal["COMPANY_ID"]), true);
    $company = $company["result"];
}

// ========================
//  3. ПОЛУЧАЕМ ДАННЫЕ ОТВЕТСТВЕННОГО
// ========================
$user = json_decode(file_get_contents($B24_WEBHOOK . "user.get.json?ID=" . $deal["ASSIGNED_BY_ID"]), true);
$user = $user["result"][0];

// ========================
//  4. ОПРЕДЕЛЯЕМ ТИП СОБЫТИЯ
// ========================
$eventType = "";
$eventText = "";

if (!empty($deal[$UF_POSITIVE_EVENT])) {
    $eventType = "Положительное событие";
    $eventText = $deal[$UF_POSITIVE_EVENT];
}

if (!empty($deal[$UF_NEGATIVE_EVENT])) {
    $eventType = "Отрицательное событие";
    $eventText = $deal[$UF_NEGATIVE_EVENT];
}

// ========================
//  5. ДАТА СОБЫТИЯ
// ========================
$eventDate = !empty($deal[$UF_EVENT_DATE]) ? $deal[$UF_EVENT_DATE] : date("d.m.Y H:i");

// ========================
//  6. ФОРМИРУЕМ СООБЩЕНИЕ
// ========================
$message  = "📌 *Новое событие в сделке*\n\n";
$message .= "👤 Ответственный: *{$user['NAME']} {$user['LAST_NAME']}*\n";
$message .= "📅 Дата: *{$eventDate}*\n";
$message .= "📄 Сделка: *{$deal['TITLE']}*\n";
$message .= "🔎 Тип события: *{$eventType}*\n";
$message .= "📝 Описание: " . (!empty($eventText) ? $eventText : "-") . "\n";
$message .= "💰 Сумма: *{$deal['OPPORTUNITY']}*\n";
$message .= "🏢 Компания: *" . (!empty($company["TITLE"]) ? $company["TITLE"] : "Без компании") . "*";

// ========================
//  7. ОТПРАВКА В TELEGRAM
// ========================
$telegramUrl = "https://api.telegram.org/bot{$TELEGRAM_BOT_TOKEN}/sendMessage";

$params = [
    "chat_id" => $TELEGRAM_CHAT_ID,
    "text" => $message,
    "parse_mode" => "Markdown"
];

file_get_contents($telegramUrl . "?" . http_build_query($params));

// ========================
echo "OK";
?>

