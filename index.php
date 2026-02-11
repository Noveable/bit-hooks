<?php
// --- НАСТРОЙКИ ---
// Убедитесь, что здесь стоят ваши правильные данные
define('B24_WEBHOOK_URL', 'https://tugur.bitrix24.ru/rest/15/c9x0qjz9quea1o01/');
define('TG_TOKEN', '8235183293:AAFjAhCwp1Y7OD21MLp8YUTSavMyf45y4Q4');
define('TG_CHAT_ID', '-5206806235');

// Коды ваших полей событий
define('POSITIVE_EVENT_FIELD', 'UF_CRM_1768751320643'); 
define('NEGATIVE_EVENT_FIELD', 'UF_CRM_1768751944908');
define('EVENT_DATE_FIELD', 'UF_CRM_1770607841259');

// === НОВОЕ ПОЛЕ! Вставьте сюда код поля "Контрольная сумма событий" из Шага 1 ===
define('EVENT_CHECKSUM_FIELD', 'UF_CRM_1770809172'); 

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

// 1. Получаем полную информацию о сделке
$dealInfo = executeB24Api('crm.deal.get', ['id' => $dealId]);
$deal = $dealInfo['result'];

// 2. Получаем ID событий.
$positiveEventIds = !empty($deal[POSITIVE_EVENT_FIELD]) ? $deal[POSITIVE_EVENT_FIELD] : [];
$negativeEventIds = !empty($deal[NEGATIVE_EVENT_FIELD]) ? $deal[NEGATIVE_EVENT_FIELD] : [];

// === НАЧАЛО НОВОЙ ЛОГИКИ С "ОТПЕЧАТКОМ" ===

// 3. Создаем "отпечаток" (хеш) из текущих ID событий
$currentEventData = json_encode(['positive' => $positiveEventIds, 'negative' => $negativeEventIds]);
$currentHash = md5($currentEventData);

// 4. Получаем старый "отпечаток", сохраненный в сделке
$storedHash = !empty($deal[EVENT_CHECKSUM_FIELD]) ? $deal[EVENT_CHECKSUM_FIELD] : '';

// 5. СРАВНИВАЕМ "ОТПЕЧАТКИ". Если они совпадают, значит, события не менялись.
if ($currentHash === $storedHash) {
    exit(); // Ничего не изменилось, завершаем работу.
}

// === КОНЕЦ НОВОЙ ЛОГИКИ С "ОТПЕЧАТКОМ" ===


// Если скрипт дошел до сюда, значит, события изменились и нужно отправить уведомление.

// 6. "Переводчик" для полей типа "Список"
function translateListValues($fieldCode, $selectedIds, $allFields) {
    if (empty($selectedIds) || !is_array($allFields)) return '';
    if (!is_array($selectedIds)) $selectedIds = [$selectedIds];
    if (!isset($allFields[$fieldCode]) || !isset($allFields[$fieldCode]['items'])) return implode(', ', $selectedIds);

    $translationMap = array_column($allFields[$fieldCode]['items'], 'VALUE', 'ID');
    $translatedValues = [];
    foreach ($selectedIds as $id) {
        if (isset($translationMap[$id])) $translatedValues[] = $translationMap[$id];
    }
    return implode(', ', $translatedValues);
}

// 7. Получаем описания полей и "переводим" ID в текст
$dealFieldsInfo = executeB24Api('crm.deal.fields', []);
$dealFields = $dealFieldsInfo['result'];
$positiveEventText = translateListValues(POSITIVE_EVENT_FIELD, $positiveEventIds, $dealFields);
$negativeEventText = translateListValues(NEGATIVE_EVENT_FIELD, $negativeEventIds, $dealFields);

// 8. Проверяем, есть ли что отправлять
if (empty($positiveEventText) && empty($negativeEventText)) {
    // Если события очистили, обновляем хеш на пустой и выходим
    executeB24Api('crm.deal.update', ['id' => $dealId, 'fields' => [EVENT_CHECKSUM_FIELD => $currentHash]]);
    exit();
}

// 9. Собираем информацию для сообщения
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

// 10. Формируем финальный текст сообщения
$message = "🔔 **Событие по сделке**\n\n";
$message .= "**Ответственный:** " . $responsibleName . "\n";
$message .= "**Дата события:** " . $dealDate . "\n";
$message .= "**Название сделки:** " . $deal['TITLE'] . "\n";
$message .= "**Сумма:** " . number_format($deal['OPPORTUNITY'], 2, ',', ' ') . ' ' . $deal['CURRENCY_ID'] . "\n";
$message .= "**Компания:** " . $companyName . "\n";
$message .= "**Тип события:**\n" . $eventType;

// 11. Отправляем сообщение в Telegram
$telegramApiUrl = 'https://api.telegram.org/bot' . TG_TOKEN . '/sendMessage';
$params = ['chat_id' => TG_CHAT_ID, 'text' => $message, 'parse_mode' => 'Markdown'];
$curl_tg = curl_init();
curl_setopt($curl_tg, CURLOPT_URL, $telegramApiUrl);
curl_setopt($curl_tg, CURLOPT_POST, true);
curl_setopt($curl_tg, CURLOPT_POSTFIELDS, http_build_query($params));
curl_setopt($curl_tg, CURLOPT_RETURNTRANSFER, true);
curl_exec($curl_tg);
curl_close($curl_tg);

// 12. ОБНОВЛЯЕМ "ОТПЕЧАТОК" В СДЕЛКЕ! Это самый важный шаг.
executeB24Api('crm.deal.update', [
    'id' => $dealId,
    'fields' => [
        EVENT_CHECKSUM_FIELD => $currentHash
    ]
]);

?>
