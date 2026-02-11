<?php
// --- НАСТРОЙКИ ---
// Убедитесь, что здесь стоят ваши правильные данные
define('B24_WEBHOOK_URL', 'https://tugur.bitrix24.ru/rest/15/c9x0qjz9quea1o01/');
define('TG_TOKEN', '8235183293:AAFjAhCwp1Y7OD21MLp8YUTSavMyf45y4Q4');
define('TG_CHAT_ID', '-5206806235');
// Коды полей, которые вы уже правильно определили
define('POSITIVE_EVENT_FIELD', 'UF_CRM_1768751320643');
define('NEGATIVE_EVENT_FIELD', 'UF_CRM_1768751944908');
define('EVENT_DATE_FIELD', 'UF_CRM_1770607841259');

// --- КОНЕЦ НАСТРОЕК ---


// Читаем данные из $_POST
$request = $_POST;

// Проверяем, что это событие обновления сделки
if (!is_array($request) || !isset($request['event']) || $request['event'] !== 'ONCRMDEALUPDATE') {
    exit();
}

$dealId = $request['data']['FIELDS']['ID'];

// Функция для выполнения запросов к API Bitrix24
function executeB24Api($method, $params) {
    $queryUrl = B24_WEBHOOK_URL . $method . '.json';
    $queryData = http_build_query($params);
    $curl = curl_init();
    curl_setopt_array($curl, array(CURLOPT_SSL_VERIFYPEER => 0, CURLOPT_POST => 1, CURLOPT_HEADER => 0, CURLOPT_RETURNTRANSFER => 1, CURLOPT_URL => $queryUrl, CURLOPT_POSTFIELDS => $queryData));
    $result = curl_exec($curl);
    curl_close($curl);
    return json_decode($result, true);
}

// === НАЧАЛО НОВОЙ ЛОГИКИ ===

// 1. Получаем полную информацию о сделке
$dealInfo = executeB24Api('crm.deal.get', ['id' => $dealId]);
$deal = $dealInfo['result'];

// 2. Получаем описания ВСЕХ полей сделки, чтобы найти наши списки
$dealFieldsInfo = executeB24Api('crm.deal.fields', []);
$dealFields = $dealFieldsInfo['result'];

// 3. Функция-"переводчик" для полей типа "Список"
function translateListValues($fieldCode, $selectedIds, $allFields) {
    if (empty($selectedIds)) {
        return '';
    }

    // Приводим ID к формату массива для единообразной обработки
    if (!is_array($selectedIds)) {
        $selectedIds = [$selectedIds];
    }

    // Ищем описание нашего поля и его элементы списка
    if (!isset($allFields[$fieldCode]) || !isset($allFields[$fieldCode]['items'])) {
        // Если что-то пошло не так, возвращаем как есть (сырые ID)
        return implode(', ', $selectedIds);
    }

    // Создаем карту "перевода": [ '903' => 'Текст значения', ... ]
    $translationMap = [];
    foreach ($allFields[$fieldCode]['items'] as $item) {
        $translationMap[$item['ID']] = $item['VALUE'];
    }

    $translatedValues = [];
    foreach ($selectedIds as $id) {
        // "Переводим" каждый ID в текст
        if (isset($translationMap[$id])) {
            $translatedValues[] = $translationMap[$id];
        }
    }

    return implode(', ', $translatedValues);
}

// 4. Получаем ID событий и "переводим" их в текст
$positiveEventIds = !empty($deal[POSITIVE_EVENT_FIELD]) ? $deal[POSITIVE_EVENT_FIELD] : [];
$negativeEventIds = !empty($deal[NEGATIVE_EVENT_FIELD]) ? $deal[NEGATIVE_EVENT_FIELD] : [];

$positiveEventText = translateListValues(POSITIVE_EVENT_FIELD, $positiveEventIds, $dealFields);
$negativeEventText = translateListValues(NEGATIVE_EVENT_FIELD, $negativeEventIds, $dealFields);

// === КОНЕЦ НОВОЙ ЛОГИКИ ===


// 5. Проверяем, что после "перевода" есть текст. Если нет - выходим.
if (empty($positiveEventText) && empty($negativeEventText)) {
    exit();
}

// 6. Собираем информацию для сообщения (уже с текстом)
$eventType = '';
if (!empty($positiveEventText)) $eventType .= "Положительное: " . $positiveEventText;
if (!empty($negativeEventText)) $eventType .= (!empty($eventType) ? "\n" : "") . "Отрицательное: " . $negativeEventText;

$responsibleName = 'Не назначен';
if (!empty($deal['ASSIGNED_BY_ID'])) {
    $userInfo = executeB24Api('user.get', ['ID' => $deal['ASSIGNED_BY_ID']]);
    if (!empty($userInfo['result'][0])) {
        $user = $userInfo['result'][0];
        $responsibleName = $user['NAME'] . ' ' . $user['LAST_NAME'];
    }
}

$companyName = 'Компания не указана';
if (!empty($deal['COMPANY_ID'])) {
    $companyInfo = executeB24Api('crm.company.get', ['id' => $deal['COMPANY_ID']]);
    if (!empty($companyInfo['result']['TITLE'])) {
        $companyName = $companyInfo['result']['TITLE'];
    }
}

$dealDateRaw = !empty($deal[EVENT_DATE_FIELD]) ? $deal[EVENT_DATE_FIELD] : $deal['DATE_MODIFY'];
$dealDate = date('d.m.Y H:i', strtotime($dealDateRaw));

// 7. Формируем финальный текст сообщения
$message = "🔔 **Событие по сделке**\n\n";
$message .= "**Ответственный:** " . $responsibleName . "\n";
$message .= "**Дата события:** " . $dealDate . "\n";
$message .= "**Название сделки:** " . $deal['TITLE'] . "\n";
$message .= "**Сумма:** " . number_format($deal['OPPORTUNITY'], 2, ',', ' ') . ' ' . $deal['CURRENCY_ID'] . "\n";
$message .= "**Компания:** " . $companyName . "\n";
$message .= "**Тип события:**\n" . $eventType;

// 8. Отправляем сообщение в Telegram
$telegramApiUrl = 'https://api.telegram.org/bot' . TG_TOKEN . '/sendMessage';
$params = ['chat_id' => TG_CHAT_ID, 'text' => $message, 'parse_mode' => 'Markdown'];
$curl = curl_init();
curl_setopt($curl, CURLOPT_URL, $telegramApiUrl);
curl_setopt($curl, CURLOPT_POST, true);
curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($params));
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
curl_exec($curl);
curl_close($curl);
?>
